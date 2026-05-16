<?php

namespace App\Lib\Fetcher;

use App\Models\Document;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Symfony\Component\DomCrawler\Crawler;

class RiigikantseleiFetcher extends BaseFetcher implements DateTypeBasedList
{
    private const SEARCH_PAGE_SIZE = 20;

    /**
     * dhs.riigikantselei.ee serves a Let's Encrypt certificate without the
     * intermediate, so OpenSSL cannot verify the chain. Disable verification
     * for this fetcher.
     */
    protected function http(): PendingRequest
    {
        return parent::http()->withoutVerifying();
    }

    public function list(Carbon $date, $type = null): array
    {
        $ids = [];
        $start = 1;
        do {
            $url = $this->buildSearchUrl($date, $start, self::SEARCH_PAGE_SIZE);
            $body = $this->http()->get($url)->body();
            $found = $this->parseSearchXml($body);
            $ids = [...$ids, ...$found];
            $start += self::SEARCH_PAGE_SIZE;
        } while (count($found) === self::SEARCH_PAGE_SIZE);

        return $ids;
    }

    public function store(?int $id = null, Document $previous = null, array $rawData = null): Document
    {
        if ($rawData !== null) {
            throw new \Exception("Not implemented");
        }
        if ($id === null) {
            throw new \Exception("Id is null");
        }

        $noteId = $this->idToNoteId($id);
        $docUrl = $this->buildDocUrl($noteId);

        if ($doc = Document::query()->where('url', $docUrl)->first()) {
            return $doc;
        }

        $xml = $this->http()->get($docUrl)->body();
        $data = $this->parseDocumentXml($xml);

        if ($data === null) {
            throw new \Exception("No data for $noteId");
        }

        $subject = trim($data['subject'] ?? '');
        $reference = trim($data['docid'] ?? '');
        $type = trim($data['doctype'] ?? $data['acttype'] ?? '');

        $docProps = [
            'organisation_id' => $this->organisation->id,
            'url' => $docUrl,
            'original_id' => $id,
            'title' => $subject !== '' ? $subject : ($type !== '' ? $type : $reference),
            'reference' => $reference,
            'registration_date' => isset($data['date']) ? Carbon::createFromFormat('d.m.Y', $data['date']) : null,
            'type' => $type,
            'series' => $data['journalkeyhierarchy'] ?? null,
            'restriction' => $data['docaccesstype'] ?? 'Avalik',
            'to' => $data['companyname'] ?? $data['govinstitution'] ?? null,
            'method' => $data['indoccat'] ?? null,
        ];

        $fileUrls = $data['_file_urls'] ?? [];
        $fileNames = $data['_file_names'] ?? [];

        $files = [];
        if ($this->downloadFiles && !empty($fileUrls)) {
            $files = $this->downloadFilesWithNames($id, $fileUrls, $fileNames);
        }

        /** @var Document $document */
        $document = Document::query()->updateOrCreate(['url' => $docUrl], $docProps);

        if (!empty($data['accessrestrictionreason'])) {
            $document->restrictions()->create(['basis' => $data['accessrestrictionreason']]);
        }

        $document->files()->saveMany($files);
        $document->ftsIndexSingle();

        return $document;
    }

    public function parseSearchXml(string $xml): array
    {
        $crawler = new Crawler();
        $crawler->addXmlContent($xml);

        $ids = [];
        $crawler->filter('document')->each(function (Crawler $doc) use (&$ids) {
            $noteId = $doc->attr('noteid');
            if ($noteId !== null) {
                $ids[] = $this->noteIdToId($noteId);
            }
        });

        return $ids;
    }

    public function parseDocumentXml(string $xml): ?array
    {
        $crawler = new Crawler();
        $crawler->addXmlContent($xml);

        $root = $crawler->filter('document')->first();
        if ($root->count() === 0) {
            return null;
        }

        $data = [];
        // Use children() to only capture top-level field/file elements,
        // not those inside nested <case><document> blocks.
        $root->children()->each(function (Crawler $el) use (&$data) {
            $tag = $el->nodeName();
            if ($tag === 'field') {
                $name = $el->attr('name');
                if ($name !== null && $name !== '' && !isset($data[$name])) {
                    $data[$name] = trim($el->text());
                }
            } elseif ($tag === 'file') {
                $href = $el->attr('href');
                $name = trim($el->text());
                if ($href) {
                    $data['_file_urls'][] = $this->absolutizeFileUrl($href);
                    $data['_file_names'][] = $name;
                }
            }
        });

        return empty($data) ? null : $data;
    }

    static function getFetcherType(): string
    {
        return 'riigikantselei-dhs';
    }

    protected function downloadFilesWithNames(int $id, array $urls, array $filenames): array
    {
        $fs = new \Illuminate\Filesystem\Filesystem();
        $temp = storage_path('temp/' . $id . '_' . \Illuminate\Support\Str::random(5));
        $fs->ensureDirectoryExists($temp);

        foreach ($urls as $index => $url) {
            $response = $this->http()->get($url);
            $filename = $filenames[$index] ?? urldecode(basename($url));
            $filename = $this->sanitizeFilename($filename);
            file_put_contents($temp . '/' . $filename, $response->body());
        }

        $files = (new \App\Lib\Parser\DirParser($temp))->parse();
        $fs->deleteDirectory($temp);

        return $files;
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = trim($filename);
        $filename = preg_replace('/\s+/', ' ', $filename);
        $filename = str_replace('%', '_percent', $filename);
        return $filename;
    }

    private function idToNoteId(int $id): string
    {
        return 'NT' . sprintf('%08X', $id);
    }

    private function noteIdToId(string $noteId): int
    {
        return hexdec(substr($noteId, 2));
    }

    private function buildDocUrl(string $noteId): string
    {
        return rtrim($this->organisation->registry_base_uri, '/') . '/documents/' . $noteId . '?open';
    }

    private function buildSearchUrl(Carbon $date, int $start, int $count): string
    {
        $d = $date->format('d.m.Y');
        $query = rawurlencode("[date]>=$d AND [date]<=$d");
        return rtrim($this->organisation->registry_base_uri, '/')
            . '/search?open&query=' . $query
            . '&SearchOrder=4&SearchMax=250'
            . '&Start=' . $start
            . '&Count=' . $count;
    }

    private function absolutizeFileUrl(string $href): string
    {
        $base = $this->organisation->registry_base_uri;
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);
        // Encode the path components so spaces and $ are handled by the HTTP client.
        $parts = array_map('rawurlencode', explode('/', ltrim($href, '/')));
        return $scheme . '://' . $host . '/' . implode('/', $parts);
    }
}
