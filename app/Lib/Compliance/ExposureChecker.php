<?php

namespace App\Lib\Compliance;

use App\Models\Document;
use App\Models\File;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * What a visitor (or a search engine) can reach right now: live HTTP probes
 * of the document page and every public R2 object, plus whether the document
 * is listed in search, the API list and the sitemap (all of which follow
 * `visible`). Used before deciding and as proof afterwards.
 */
class ExposureChecker
{
    // Pages hit our own php-fpm (5 workers, one busy serving this call), so
    // probe in small concurrent batches.
    private const CONCURRENCY = 4;
    private const TIMEOUT = 10;

    /**
     * @param iterable<Document> $documents
     * @return array<int, array> one report per document
     */
    public function documents(iterable $documents): array
    {
        $reports = [];
        foreach ($documents as $document) {
            $objects = [];
            foreach (File::query()->where('document_id', $document->id)->orderBy('id')->get() as $file) {
                foreach (array_filter([$file->original_withheld ? null : $file->location, $file->redacted_location]) as $key) {
                    $objects[] = ['file_id' => $file->id, 'key' => $key, 'url' => $this->objectUrl($key)];
                }
            }
            $reports[] = [
                'document_id' => $document->id,
                'page' => ['url' => $this->siteUrl("/dokumendid/{$document->id}")],
                'objects' => $objects,
                'listed_in_search_api_sitemap' => (bool)$document->visible,
            ];
        }

        $urls = [];
        foreach ($reports as $r) {
            $urls[] = $r['page']['url'];
            array_push($urls, ...array_column($r['objects'], 'url'));
        }
        $status = $this->probeMany($urls);

        $now = now()->toIso8601String();
        foreach ($reports as &$r) {
            $r['page'] += $status[$r['page']['url']];
            foreach ($r['objects'] as &$o) {
                $o += $status[$o['url']];
            }
            unset($o);
            $r['exposed'] = self::served($r['page']['http']) || $r['listed_in_search_api_sitemap']
                || collect($r['objects'])->contains(fn($o) => self::served($o['http']));
            $r['checked_at'] = $now;
        }

        return $reports;
    }

    public function document(Document $document): array
    {
        return $this->documents([$document])[0];
    }

    /**
     * @param string[] $keys
     * @return array<int, array>
     */
    public function objects(array $keys): array
    {
        $urls = array_map(fn($k) => $this->objectUrl($k), $keys);
        $status = $this->probeMany($urls);
        $now = now()->toIso8601String();

        return array_map(fn($k, $u) => [
            'key' => $k, 'url' => $u, ...$status[$u], 'exposed' => self::served($status[$u]['http']), 'checked_at' => $now,
        ], $keys, $urls);
    }

    public function objectUrl(string $key): string
    {
        $base = rtrim((string)config('filesystems.disks.r2.url'), '/');
        return $base . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    private function siteUrl(string $path): string
    {
        return rtrim((string)config('app.url'), '/') . $path;
    }

    /** 200, or 206 for the one-byte range request: the content is served. */
    private static function served(?int $status): bool
    {
        return $status === 200 || $status === 206;
    }

    /**
     * Fetch the first byte of each URL, the way a visitor would. Not HEAD:
     * Cloudflare answers HEAD from R2 directly but serves GET from its edge
     * cache (max-age 4h), so a deleted file can still be downloadable while
     * HEAD says 404. Returns the status (null when the probe itself failed:
     * timeout, DNS) and Cloudflare's cache status.
     *
     * @param string[] $urls
     * @return array<string, array{http: ?int, cache: ?string}>
     */
    private function probeMany(array $urls): array
    {
        $status = [];
        foreach (array_chunk(array_values(array_unique($urls)), self::CONCURRENCY) as $chunk) {
            $responses = Http::pool(fn(Pool $pool) => array_map(
                fn($url) => $pool->as($url)
                    ->timeout(self::TIMEOUT)
                    ->withHeaders(['User-Agent' => 'dokumendiregistrid-compliance-probe', 'Range' => 'bytes=0-0'])
                    ->withoutRedirecting()
                    ->get($url),
                $chunk,
            ));
            foreach ($chunk as $url) {
                $response = $responses[$url] ?? null;
                $status[$url] = $response instanceof Response
                    ? ['http' => $response->status(), 'cache' => $response->header('cf-cache-status') ?: null]
                    : ['http' => null, 'cache' => null];
            }
        }
        return $status;
    }
}
