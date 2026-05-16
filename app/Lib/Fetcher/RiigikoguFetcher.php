<?php

namespace App\Lib\Fetcher;

use App\Models\Document;
use Carbon\Carbon;
use Symfony\Component\DomCrawler\Crawler;

class RiigikoguFetcher extends BaseFetcher implements DateTypeBasedList
{
    private const IGNORED_TYPE = 'Üksuse istungi päevakorrapunkt';

    /**
     * Cached map of doc UUID -> type extracted while paging the list.
     * The detail page has no "Dokumendi liik" field, so the type is
     * carried from the search results table.
     */
    private array $typesByUuid = [];

    public function list(Carbon $date, $type = null): array
    {
        $uuids = [];
        $page = 1;
        do {
            $url = $this->buildListUrl($date, $page);
            $body = $this->http()->get($url)->body();
            [$pageUuids, $hasNext] = $this->parseListPage($body);
            $uuids = [...$uuids, ...$pageUuids];
            $page++;
        } while ($hasNext);

        return $uuids;
    }

    public function parseListPage(string $html): array
    {
        $crawler = new Crawler($html);
        $uuids = [];

        $crawler->filter('tbody.search-results-eelnoud tr')->each(function (Crawler $row) use (&$uuids) {
            $cells = $row->filter('td');
            if ($cells->count() < 4) {
                return;
            }
            $type = trim($cells->eq(3)->text());
            if ($type === self::IGNORED_TYPE) {
                return;
            }
            $link = $cells->eq(2)->filter('a');
            if ($link->count() === 0) {
                return;
            }
            $uuid = $this->extractUuidFromUrl($link->attr('href'));
            if ($uuid !== null) {
                $uuids[] = $uuid;
                $this->typesByUuid[$uuid] = $type;
            }
        });

        $hasNext = $crawler->filter('.pagination a.next')->count() > 0;

        return [$uuids, $hasNext];
    }

    public function store(int|string|null $id = null, Document $previous = null, array $rawData = null): Document
    {
        if ($rawData !== null) {
            throw new \Exception("Not implemented");
        }
        if ($id === null) {
            throw new \Exception("Id is null");
        }

        $uuid = (string)$id;
        $docUrl = $this->buildDocUrl($uuid);

        if ($doc = Document::query()->where('url', $docUrl)->first()) {
            return $doc;
        }

        $html = $this->http()->get($docUrl)->body();
        $data = $this->parseDocumentPage($html);

        $regDateRaw = $data['Dokumendi loomise kuupäev'] ?? $data['Kuupäev'] ?? null;

        $docProps = [
            'organisation_id' => $this->organisation->id,
            'url' => $docUrl,
            'original_id' => $uuid,
            'title' => $data['Pealkiri'] ?? '',
            'reference' => $data['Dokumendi viit'] ?? '',
            'registration_date' => $regDateRaw ? Carbon::createFromFormat('d.m.Y', $regDateRaw) : null,
            'type' => $this->typesByUuid[$uuid] ?? '',
            'dossier' => $data['Toimik'] ?? null,
            'restriction' => $data['_restriction'],
            'to' => $data['Autor/Adressaat'] ?? null,
            'method' => $data['Saabumise/Saatmise viis'] ?? null,
        ];

        $fileUrls = $data['_file_urls'];
        $fileNames = $data['_file_names'];
        $restrictionBases = $data['_restriction_bases'];

        $files = [];
        if ($this->downloadFiles && !empty($fileUrls)) {
            $files = $this->downloadFilesWithNames($uuid, $fileUrls, $fileNames);
        }

        /** @var Document $document */
        $document = Document::query()->updateOrCreate(['url' => $docUrl], $docProps);

        $document->files()->saveMany($files);
        foreach ($restrictionBases as $basis) {
            $document->restrictions()->create(['basis' => $basis]);
        }

        $document->ftsIndexSingle();

        return $document;
    }

    private function buildListUrl(Carbon $date, int $page): string
    {
        $d = $date->format('d.m.Y');
        $params = http_build_query([
            'pg' => $page,
            'searchIn' => 'documents',
            'startDate' => $d,
            'endDate' => $d,
            'order' => 'DESC',
            'sortBy' => 'created',
            'prepage' => 25,
        ]);

        return rtrim($this->organisation->registry_base_uri, '/') . '/?' . $params;
    }

    private function buildDocUrl(string $uuid): string
    {
        return rtrim($this->organisation->registry_base_uri, '/') . '/dokument/' . $uuid . '/';
    }

    private function extractUuidFromUrl(string $url): ?string
    {
        if (preg_match('|dokument/([0-9a-f-]{36})|i', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    public function parseDocumentPage(string $html): array
    {
        $crawler = new Crawler($html);

        $data = [];

        $tables = $crawler->filter('section.content-section table.table.table-striped');

        $metaTable = $tables->eq(0);
        if ($metaTable->count() > 0) {
            $metaTable->filter('tr')->each(function (Crawler $tr) use (&$data) {
                $cells = $tr->filter('td');
                if ($cells->count() < 2) {
                    return;
                }
                $label = rtrim(trim($cells->eq(0)->text()), ':');
                $value = trim($cells->eq(1)->text());
                if ($label !== '') {
                    $data[$label] = $value;
                }
            });
        }

        $fileUrls = [];
        $fileNames = [];
        $restrictionBases = [];
        $anyPublic = false;
        $anyRestricted = false;

        if ($tables->count() > 1) {
            $tables->eq(1)->filter('tbody tr')->each(function (Crawler $tr) use (
                &$fileUrls, &$fileNames, &$restrictionBases, &$anyPublic, &$anyRestricted
            ) {
                $cells = $tr->filter('td');
                if ($cells->count() < 3) {
                    return;
                }
                $nameCell = $cells->eq(0);
                $restrictionText = trim($cells->eq(1)->text());
                $basisText = trim($cells->eq(2)->text());

                if ($restrictionText === 'Avalik') {
                    $anyPublic = true;
                    $link = $nameCell->filter('a');
                    if ($link->count() > 0 && $link->attr('href')) {
                        $fileUrls[] = $this->absolutizeUrl($link->attr('href'));
                        $fileNames[] = trim($link->text());
                    }
                } else {
                    $anyRestricted = true;
                    if ($basisText !== '') {
                        $restrictionBases[] = $basisText;
                    }
                }
            });
        }

        $data['_file_urls'] = $fileUrls;
        $data['_file_names'] = $fileNames;
        $data['_restriction_bases'] = array_values(array_unique($restrictionBases));
        $data['_restriction'] = $anyPublic || !$anyRestricted ? 'Avalik' : 'Asutusesiseseks kasutamiseks';

        return $data;
    }

    private function absolutizeUrl(string $href): string
    {
        if (str_starts_with($href, 'http')) {
            return $href;
        }
        $base = $this->organisation->registry_base_uri;
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);
        return $scheme . '://' . $host . '/' . ltrim($href, '/');
    }

    protected function downloadFilesWithNames(string $id, array $urls, array $filenames): array
    {
        $fs = new \Illuminate\Filesystem\Filesystem();
        $temp = storage_path('temp/' . $id . '_' . \Illuminate\Support\Str::random(5));
        $fs->ensureDirectoryExists($temp);

        foreach ($urls as $index => $url) {
            $response = $this->http()->get($url);
            $headerName = $this->filenameFromContentDisposition($response->header('Content-Disposition') ?? '');
            $label = $filenames[$index] ?? '';
            $filename = $headerName
                ?? ($label !== '' ? $label : urldecode(basename($url)));
            $filename = $this->sanitizeFilename($filename);
            file_put_contents($temp . '/' . $filename, $response->body());
        }

        $files = (new \App\Lib\Parser\DirParser($temp))->parse();
        $fs->deleteDirectory($temp);

        return $files;
    }

    /**
     * Extract a filename from a Content-Disposition header, preferring the
     * RFC 5987 `filename*=UTF-8''…` form (which Riigikogu always sets) over
     * the legacy `filename="…"` form (which may be RFC 2047 encoded).
     */
    private function filenameFromContentDisposition(string $header): ?string
    {
        if ($header === '') {
            return null;
        }
        if (preg_match("/filename\\*\\s*=\\s*([^']+)'[^']*'([^;\\r\\n]+)/i", $header, $m)) {
            $charset = strtoupper(trim($m[1]));
            $decoded = urldecode(trim($m[2]));
            if ($charset !== 'UTF-8' && $charset !== '' && function_exists('mb_convert_encoding')) {
                $decoded = mb_convert_encoding($decoded, 'UTF-8', $charset);
            }
            return $decoded !== '' ? $decoded : null;
        }
        if (preg_match('/filename\s*=\s*"?([^";\r\n]+)"?/i', $header, $m)) {
            $name = trim($m[1], "\"'");
            if (str_contains($name, '=?') && function_exists('iconv_mime_decode')) {
                $decoded = @iconv_mime_decode($name, 0, 'UTF-8');
                if (is_string($decoded) && $decoded !== '') {
                    return $decoded;
                }
            }
            return $name !== '' ? $name : null;
        }
        return null;
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = trim($filename);
        $filename = preg_replace('/\s+/', ' ', $filename);
        $filename = str_replace('%', '_percent', $filename);
        return $filename;
    }

    static function getFetcherType(): string
    {
        return 'riigikogu';
    }
}
