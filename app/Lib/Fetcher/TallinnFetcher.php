<?php

namespace App\Lib\Fetcher;

use App\Models\Document;
use Carbon\Carbon;
use Symfony\Component\DomCrawler\Crawler;

use function Laravel\Prompts\warning;

class TallinnFetcher extends BaseFetcher implements Enumeratable
{
    private const BASE_URL = 'https://dhs.tallinn.ee/atp/';
    private const DOC_URL_TEMPLATE = 'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=%d';

    public function store(?int $id = null, Document $previous = null, array $rawData = null): Document
    {
        if ($id && $rawData) {
            throw new \Exception("Id and rawData are mutually exclusive");
        }

        if (!$id && $rawData) {
            $data = $rawData;
            $id = $data['_id'];
            $docUrl = sprintf(self::DOC_URL_TEMPLATE, $id);

            if ($doc = Document::query()->where('url', $docUrl)->first()) {
                return $doc;
            }
        } else {
            $docUrl = sprintf(self::DOC_URL_TEMPLATE, $id);

            if ($doc = Document::query()->where('url', $docUrl)->first()) {
                return $doc;
            }

            $data = $this->fetchAndParse($id);
        }

        if ($data === null) {
            throw new \Exception("No data for $id");
        }

        $docProps = [
            'organisation_id' => $this->organisation->id,
            'url' => $docUrl,
            'original_id' => $id,
            'title' => $data['Pealkiri'] ?? $data['Lepingu objekt'] ?? $data['Objekt'] ?? null,
            'reference' => $data['Reg nr'] ?? null,
            'registration_date' => isset($data['Reg kpv']) ? Carbon::parse($data['Reg kpv']) : null,
            'type' => $data['Liik'] ?? null,
            'restriction' => $data['Juurdepääsupiirang'] ?? 'Avalik',
            'to' => $data['Kellele'] ?? $data['Saatja'] ?? $data['Lepingu pool'] ?? null,
            'method' => $data['Saatmisviis'] ?? null,
            'responsible' => $data['Täitja'] ?? $data['Asutus'] ?? null,
        ];

        $fileUrls = $data['_file_urls'] ?? [];
        $fileNames = $data['Failid'] ?? [];

        $files = [];
        if ($this->downloadFiles && !empty($fileUrls)) {
            info("Downloading files for $id");
            $files = $this->downloadFilesWithNames($id, $fileUrls, $fileNames);
            info("Downloaded files for $id");
        }

        /** @var Document $document */
        $document = Document::query()->updateOrCreate([
            'url' => $docUrl,
        ], $docProps);

        if (!empty($data['Juurdepääsupiirangu alus'])) {
            $document->restrictions()->create(['basis' => $data['Juurdepääsupiirangu alus']]);
        }

        $document->files()->saveMany($files);
        info("Saved files for $id");

        $document->ftsIndexSingle();

        info("Stored $id");

        return $document;
    }

    /**
     * Download files using the provided filenames instead of relying on Content-Disposition headers.
     * Tallinn's server doesn't return Content-Disposition headers.
     *
     * @param int $id
     * @param array $urls
     * @param array $filenames
     * @return array
     */
    protected function downloadFilesWithNames(int $id, array $urls, array $filenames): array
    {
        $fs = new \Illuminate\Filesystem\Filesystem();
        $temp = storage_path('temp/' . $id . '_' . \Illuminate\Support\Str::random(5));
        $fs->ensureDirectoryExists($temp);

        foreach ($urls as $index => $url) {
            $response = $this->http()->get($url);

            // Use the filename from parsed HTML if available, otherwise fall back to URL basename
            $filename = $filenames[$index] ?? urldecode(basename($url));
            $filename = $this->sanitizeFilename($filename);

            file_put_contents($temp . '/' . $filename, $response->body());
        }

        $files = (new \App\Lib\Parser\DirParser($temp))->parse();
        $fs->deleteDirectory($temp);

        return $files;
    }

    /**
     * Sanitize filename for storage.
     */
    private function sanitizeFilename(string $filename): string
    {
        $filename = trim($filename);
        $filename = preg_replace('/\s+/', ' ', $filename);
        $filename = str_replace('%', '_percent', $filename);
        return $filename;
    }

    public function fetchAndParse(int $id): ?array
    {
        $url = sprintf(self::DOC_URL_TEMPLATE, $id);
        $response = $this->http()->get($url);

        if (!$response->successful()) {
            return null;
        }

        return $this->parseHtml($response->body());
    }

    public function parseHtml(string $html): ?array
    {
        $crawler = new Crawler($html);

        // Check if document exists - look for the document_container div
        $documentContainer = $crawler->filter('#document_container');
        if ($documentContainer->count() === 0) {
            return null;
        }

        // Check if the container has any rows (non-existing documents have empty container)
        $rows = $documentContainer->filter('.row');
        if ($rows->count() === 0) {
            return null;
        }

        $data = [];

        // Parse the div.row elements - each has div.detail_label and div.detail_desc
        $rows->each(function (Crawler $row) use (&$data) {
            $labelNode = $row->filter('.detail_label');
            $valueNode = $row->filter('.detail_desc');

            if ($labelNode->count() > 0 && $valueNode->count() > 0) {
                $label = trim($labelNode->text());

                // Handle file links specially
                if ($label === 'Failid') {
                    $fileLinks = $valueNode->filter('a');
                    $fileUrls = [];
                    $fileLinks->each(function (Crawler $link) use (&$fileUrls) {
                        $href = $link->attr('href');
                        if ($href) {
                            // Convert relative URLs to absolute
                            if (!str_starts_with($href, 'http')) {
                                $href = "https://dhs.tallinn.ee/" . ltrim($href, '/');
                            }
                            $fileUrls[] = $href;
                        }
                    });
                    $data['_file_urls'] = $fileUrls;
                    $data[$label] = $fileLinks->each(fn(Crawler $link) => trim($link->text()));
                } elseif ($label === 'Lepingu pool' && isset($data['Lepingu pool'])) {
                    // Handle multiple "Lepingu pool" entries - keep the second one (counterparty)
                    $data['Lepingu pool'] = trim($valueNode->text());
                } else {
                    $data[$label] = trim($valueNode->text());
                }
            }
        });

        return empty($data) ? null : $data;
    }

    public function enumerateBackwards(int $maxId, int $minId = 1, callable $callback = null): void
    {
        for ($i = $maxId; $i >= $minId; $i--) {
            $data = $this->fetchAndParse($i);
            if ($data === null) {
                warning("No data for $i");
                continue;
            }
            $data['_id'] = $i;
            $callback($data);
        }
    }

    public function enumerateForwards(int $minId, int $maxFailures = 20, callable $callback = null): void
    {
        $failures = 0;
        for ($i = $minId; $failures < $maxFailures; $i++) {
            $data = $this->fetchAndParse($i);
            if ($data === null) {
                warning("No data for $i");
                $failures++;
                continue;
            }
            $failures = 0; // Reset failures on success
            $data['_id'] = $i;
            $callback($data);
        }
    }

    public function getCurrentMaxId(): int
    {
        return Document::query()->where('organisation_id', $this->organisation->id)->max('original_id') ?? 5711000;
    }

    static function getFetcherType(): string
    {
        return 'tallinn-atp';
    }
}
