<?php

namespace App\Lib\Compliance;

use App\Models\Document;
use App\Models\File;
use Illuminate\Support\Facades\Storage;

/**
 * Maps a URL quoted in a letter (AKI, an agency, a data subject) to what we
 * hold: a document page on the site, a file in the public R2 bucket, or the
 * document's page in its source registry.
 */
class UrlResolver
{
    public const KIND_DOCUMENT = 'document';
    public const KIND_FILE = 'file';
    public const KIND_ORPHAN_OBJECT = 'orphan_object';
    public const KIND_UNKNOWN = 'unknown';

    /**
     * @return array{url: string, kind: string, document_id: ?int, file_id: ?int, file_key: ?string, note: ?string}
     */
    public function resolve(string $url): array
    {
        $url = trim($url);
        $result = ['url' => $url, 'kind' => self::KIND_UNKNOWN, 'document_id' => null, 'file_id' => null, 'file_key' => null, 'note' => null];

        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        if (in_array($host, config('compliance.site_hosts'), true)) {
            if (preg_match('#^/(?:api/)?(?:dokumendid|documents)/(\d+)#', $path, $m) && Document::whereKey((int)$m[1])->exists()) {
                return ['kind' => self::KIND_DOCUMENT, 'document_id' => (int)$m[1]] + $result;
            }
            return ['note' => 'Site URL without a known document id (search pages name no document; resolve their hits instead).'] + $result;
        }

        if ($host === $this->r2Host()) {
            $key = rawurldecode(ltrim($path, '/'));
            $file = File::query()->where('location', $key)->orWhere('redacted_location', $key)->first();
            if ($file) {
                return ['kind' => self::KIND_FILE, 'document_id' => $file->document_id, 'file_id' => $file->id, 'file_key' => $key] + $result;
            }
            $exists = $key !== '' && Storage::disk('r2')->exists($key);
            return [
                'kind' => $exists ? self::KIND_ORPHAN_OBJECT : self::KIND_UNKNOWN,
                'file_key' => $key,
                'note' => $exists ? 'Public object with no files row.' : 'No such object in the public bucket (already deleted, or the URL is mangled).',
            ] + $result;
        }

        $document = Document::query()->where('url', $url)->first();
        if ($document) {
            return ['kind' => self::KIND_DOCUMENT, 'document_id' => $document->id, 'note' => 'Matched by source-registry URL.'] + $result;
        }

        return ['note' => 'Not a site, file or known source-registry URL.'] + $result;
    }

    public function r2Host(): string
    {
        return strtolower((string)parse_url((string)config('filesystems.disks.r2.url'), PHP_URL_HOST));
    }
}
