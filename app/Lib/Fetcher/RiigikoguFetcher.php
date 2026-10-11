<?php

namespace App\Lib\Fetcher;

use App\Lib\Recheck\RemoteCheck;
use App\Models\Document;
use Illuminate\Http\Client\Response;
use Carbon\Carbon;
use Symfony\Component\DomCrawler\Crawler;

class RiigikoguFetcher extends BaseFetcher implements DateTypeBasedList
{
    /**
     * Since the 2026 redesign the register's list is rendered client-side
     * from this public JSON API (the <dokumendiregister-list api-base>).
     */
    private const API_BASE = 'https://www.riigikogu.ee/wp-json/riigikogu/v1/ems';
    private const PAGE_SIZE = 100;

    /** Agenda items of unit sittings are not documents worth mirroring. */
    private const IGNORED_TYPE = 'unitAgendaItemDocument';

    /** The register's own labels for its type codes (its documentTypes filter). */
    private const TYPE_LABELS = [
        'jobDescriptionDocument' => 'Ametijuhend',
        'aruanne' => 'Aruanne',
        'interpellationsDocument' => 'Arupärimine',
        'interpellationsAnswerDocument' => 'Arupärimise vastamise teade',
        'elDocument' => 'EL dokument',
        'letterDocument' => 'Kiri',
        'writtenQuestionDocument' => 'Kirjalik küsimus',
        'writtenQuestionAnswerDocument' => 'Kirjaliku küsimuse vastus',
        'collectiveAddressDocument' => 'Kollektiivne pöördumine',
        'regulationDocument' => 'Korraldus',
        'directiveDocument' => 'Käskkiri',
        'contractDocument' => 'Leping',
        'lisadokumendid' => 'Lisadokumendid',
        'otherQuestionDocument' => 'Muud küsimused',
        'decisionDocument' => 'Otsus',
        'protokoll' => 'Protokoll',
        'riigikoguSeisukoht' => 'Riigikogu seisukoht',
        'commissionOpinionDocument' => 'Valdkondliku komisjoni arvamus',
        'yldine' => 'Üldine',
    ];

    /**
     * What the list knows about each document (type label, reference,
     * creation date), carried to store(): the detail page does not show
     * the type and not always the reference.
     *
     * @var array<string, array{type: string, reference: ?string, created: ?string}>
     */
    private array $listed = [];

    public function list(Carbon $date, $type = null): array
    {
        $uuids = [];
        $page = 0;
        do {
            $response = $this->http()->get(self::API_BASE . '/documents', [
                'page' => $page,
                'size' => self::PAGE_SIZE,
                'createdStart' => $date->format('Y-m-d'),
                'createdEnd' => $date->format('Y-m-d'),
                'sort' => 'created,DESC',
            ]);
            [$pageUuids, $hasNext] = $this->parseListResponse($response->status() === 400 ? [] : (array)$response->json());
            $uuids = [...$uuids, ...$pageUuids];
            $page++;
        } while ($hasNext);

        return $uuids;
    }

    /** @return array{0: string[], 1: bool} uuids on this page, and whether another page follows */
    public function parseListResponse(array $json): array
    {
        $uuids = [];
        foreach ($json['_embedded']['content'] ?? [] as $row) {
            $uuid = $row['uuid'] ?? null;
            $code = (string)($row['documentType'] ?? '');
            if (!$uuid || $code === self::IGNORED_TYPE) {
                continue;
            }
            $uuids[] = $uuid;
            $this->listed[$uuid] = [
                'type' => self::TYPE_LABELS[$code] ?? $code,
                'reference' => $row['reference'] ?? null,
                'created' => $row['created'] ?? null,
            ];
        }
        $page = $json['page'] ?? [];
        $hasNext = isset($page['number'], $page['totalPages']) && $page['number'] + 1 < $page['totalPages'];

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
        $listed = $this->listed[$uuid] ?? [];
        $registered = $regDateRaw
            ? Carbon::createFromFormat('d.m.Y', $regDateRaw)
            : (!empty($listed['created']) ? Carbon::parse($listed['created']) : null);

        $docProps = [
            'organisation_id' => $this->organisation->id,
            'url' => $docUrl,
            'original_id' => $uuid,
            'title' => $data['Pealkiri'] ?? '',
            'reference' => $data['Dokumendi viit'] ?? $listed['reference'] ?? '',
            'registration_date' => $registered,
            'type' => $listed['type'] ?? '',
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

    public function checkRemote(Document $document): RemoteCheck
    {
        return $this->performCheck($document->url, function (Response $response) {
            $html = $response->body();
            $crawler = new Crawler($html);

            // 2026 redesign: unknown UUIDs render "Laadimine ebaõnnestus" with no metadata.
            $redesign = $crawler->filter('data-table, div.font-bold')->count() > 0 || str_contains($html, 'Laadimine ebaõnnestus');
            if ($crawler->filter('section.content-section')->count() === 0 && !$redesign) {
                return RemoteCheck::error(RemoteCheck::ERROR_UNPARSEABLE, $response->status(), 'No content section in response');
            }

            $data = $this->parseDocumentPage($html);
            $meta = array_filter($data, fn($key) => !str_starts_with((string)$key, '_'), ARRAY_FILTER_USE_KEY);

            // The site answers 200 with an empty metadata table for unknown UUIDs.
            if ($meta === []) {
                return RemoteCheck::gone($response->status());
            }

            return RemoteCheck::fromRestriction(
                $data['_restriction'],
                $data['_restriction_bases'],
                null,
                $response->status(),
            );
        });
    }

    private function buildDocUrl(string $uuid): string
    {
        return rtrim($this->organisation->registry_base_uri, '/') . '/dokument/' . $uuid . '/';
    }

    public function parseDocumentPage(string $html): array
    {
        $crawler = new Crawler($html);

        $data = [];

        $tables = $crawler->filter('section.content-section table.table.table-striped');
        if ($tables->count() === 0) {
            return $this->parseRedesignedDocumentPage($crawler);
        }

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

    /**
     * The 2026 redesign: metadata as "label: value" div pairs, files in a
     * <data-table> of custom elements whose <file-download> carries the URL,
     * name and restriction type. Same return shape as parseDocumentPage.
     */
    private function parseRedesignedDocumentPage(Crawler $crawler): array
    {
        $data = [];
        $crawler->filter('div.font-bold')->each(function (Crawler $label) use (&$data) {
            $value = $label->nextAll()->filter('div')->first();
            $key = rtrim(trim($label->text()), ':');
            if ($key !== '' && $value->count() > 0) {
                $data[$key] = trim(preg_replace('/\s+/u', ' ', $value->text()));
            }
        });

        $fileUrls = [];
        $fileNames = [];
        $restrictionBases = [];
        $anyPublic = false;
        $anyRestricted = false;
        $crawler->filter('data-table table-body table-row')->each(function (Crawler $row) use (
            &$fileUrls, &$fileNames, &$restrictionBases, &$anyPublic, &$anyRestricted
        ) {
            $cells = $row->filter('table-cell');
            if ($cells->count() < 3) {
                return;
            }
            $file = $cells->eq(0)->filter('file-download');
            $restrictionText = trim($cells->eq(1)->text());
            $basisText = trim($cells->eq(2)->text());
            $public = $restrictionText === 'Avalik' || ($file->count() > 0 && $file->attr('access-restriction-type') === 'PUBLIC');

            if ($public) {
                $anyPublic = true;
                if ($file->count() > 0 && $file->attr('url')) {
                    $fileUrls[] = $this->absolutizeUrl($file->attr('url'));
                    $fileNames[] = trim((string)($file->attr('file-name') ?: $file->attr('title')));
                }
            } else {
                $anyRestricted = true;
                if ($basisText !== '') {
                    $restrictionBases[] = $basisText;
                }
            }
        });

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
