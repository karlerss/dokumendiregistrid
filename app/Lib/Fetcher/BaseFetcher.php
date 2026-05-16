<?php

namespace App\Lib\Fetcher;

use App\Lib\Parser\DirParser;
use App\Models\Document;
use App\Models\File;
use App\Models\Organisation;
use Carbon\Carbon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class BaseFetcher
{
    public const USER_AGENT = 'dokumendiregistrid.karlerss.com';
    public bool $downloadFiles = true;

    abstract public function store(?int $id = null, Document $previous = null, array $rawData = null): Document;

    abstract static function getFetcherType(): string;

    public function __construct(protected Organisation $organisation)
    {

    }

    /**
     * @param bool $downloadFiles
     * @return AdrFetcher
     */
    public function setDownloadFiles(bool $downloadFiles): static
    {
        $this->downloadFiles = $downloadFiles;
        return $this;
    }

    /**
     * @param int $id
     * @param array $links
     * @return File[]|array
     */
    public function downloadFiles(int $id, array $links): array
    {
        $fs = new Filesystem();
        $temp = storage_path('temp/' . $id . '_' . Str::random(5));
        $fs->ensureDirectoryExists($temp);
        foreach ($links as $link) {
            $this->saveFileToDirectoryWithNameFromHeader($link, $temp);
        }
        $files = (new DirParser($temp))->parse();
        $fs->deleteDirectory($temp);
        return $files;
    }

    /**
     * Save the file to the directory with the name from the Content-Disposition header.
     *
     * @param string $url
     * @param string $dir
     * @return void
     */
    private function saveFileToDirectoryWithNameFromHeader(string $url, string $dir)
    {
        try {
            $response = $this->http()->get($url);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            if ($e->response->status() === 500) {
                logger()->warning("Skipping file (server returned 500): $url");
                return;
            }
            throw $e;
        }

        $contentDisposition = $response->header('Content-Disposition') ?? '';

        if (preg_match('/filename[^;=\n]*=(([\'"]).*?\2|[^;\n]*)/', $contentDisposition, $matches)) {
            $filename = trim($matches[1], '"\'');
            $filename = urldecode($filename);
        } else {
            $filename = urldecode(basename($url));
        }

        $filename = $this->sanitizeFilenameForR2($filename);

        file_put_contents($dir . '/' . $filename, $response->body());
    }

    private function sanitizeFilenameForR2($filename): string
    {
        // Strip NUL bytes and any path separators (defence against path traversal
        // via attacker-controlled Content-Disposition / URL basename).
        $filename = str_replace(["\0", "/", "\\"], '', $filename);

        // Reduce to the final path component only; this also collapses any
        // residual traversal sequences a server might have sent.
        $filename = basename($filename);

        // After stripping, refuse pure-dot names like "." or "..".
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = 'file_' . Str::random(8);
        }

        // Trim whitespace
        $filename = trim($filename);

        // Normalize multiple spaces to a single space
        $filename = preg_replace('/\s+/', ' ', $filename);

        // Replace percent signs to avoid double encoding issues
        $filename = str_replace('%', '_percent', $filename);

        return $filename;
    }

    protected function http(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => self::USER_AGENT])->retry(3, 1000);
    }
}
