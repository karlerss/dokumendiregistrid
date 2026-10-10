<?php

namespace App\Lib;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Purges public R2 file URLs from Cloudflare's edge cache. The bucket is
 * served through Cloudflare with max-age 4h, so a deleted or withheld file
 * otherwise stays downloadable from the cache for hours. Called after every
 * delete of a public object; a purge failure is logged and reported, never
 * thrown, because the delete itself has already happened.
 */
class CdnPurge
{
    // Cloudflare accepts at most 30 URLs per purge call on non-Enterprise plans.
    private const BATCH = 30;

    public function configured(): bool
    {
        return (string)config('services.cloudflare.zone_id') !== '' && (string)config('services.cloudflare.api_token') !== '';
    }

    /**
     * @param string[] $keys object keys in the public bucket
     * @return array{purged: string[], failed: string[], skipped: bool}
     */
    public function keys(array $keys): array
    {
        $urls = array_values(array_unique(array_map([self::class, 'url'], array_filter($keys))));
        $result = ['purged' => [], 'failed' => [], 'skipped' => false];
        if ($urls === []) {
            return $result;
        }
        if (!$this->configured()) {
            Log::warning('CDN purge skipped: CLOUDFLARE_ZONE_ID/CLOUDFLARE_API_TOKEN not set', ['urls' => $urls]);
            return ['skipped' => true] + $result;
        }

        foreach (array_chunk($urls, self::BATCH) as $batch) {
            try {
                $response = Http::timeout(15)
                    ->withToken((string)config('services.cloudflare.api_token'))
                    ->post('https://api.cloudflare.com/client/v4/zones/' . config('services.cloudflare.zone_id') . '/purge_cache', ['files' => $batch]);
                $ok = $response->successful() && $response->json('success') === true;
            } catch (\Throwable $e) {
                $ok = false;
                $response = null;
            }
            if ($ok) {
                array_push($result['purged'], ...$batch);
            } else {
                array_push($result['failed'], ...$batch);
                Log::error('CDN purge failed', ['urls' => $batch, 'status' => $response?->status(), 'body' => mb_substr((string)$response?->body(), 0, 500)]);
            }
        }
        return $result;
    }

    /** Public URL of an object key, encoded the way browsers request it. */
    public static function url(string $key): string
    {
        $base = rtrim((string)config('filesystems.disks.r2.url'), '/');
        return $base . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}
