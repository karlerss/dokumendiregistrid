<?php

namespace App\Lib\Fetcher;

use App\Lib\Parser\DirParser;
use App\Lib\Recheck\RemoteCheck;
use App\Models\Document;
use App\Models\File;
use App\Models\Organisation;
use Carbon\Carbon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class BaseFetcher
{
    public const USER_AGENT = 'dokumendiregistrid.karlerss.com';
    public bool $downloadFiles = true;

    abstract public function store(?int $id = null, Document $previous = null, array $rawData = null): Document;

    abstract static function getFetcherType(): string;

    /**
     * Probe the source registry for the current state of an already stored
     * document: still public, restricted, gone, or could not be determined.
     */
    abstract public function checkRemote(Document $document): RemoteCheck;

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

    /**
     * Request builder shared by ingestion and re-checks. Subclasses override
     * this for host-specific options (e.g. TLS verification).
     */
    protected function baseHttp(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => self::USER_AGENT]);
    }

    /**
     * Request builder used by ingestion: retries everything, follows redirects.
     */
    protected function http(): PendingRequest
    {
        return $this->baseHttp()->retry(3, 1000);
    }

    /**
     * Request builder used by re-checks: short timeouts, no redirects, and
     * retries only for connection failures and 5xx. A 4xx must not be retried
     * (it is the normal "document gone" answer) and must not throw.
     */
    protected function checkHttp(): PendingRequest
    {
        return $this->baseHttp()
            ->timeout((int)config('recheck.timeout', 15))
            ->connectTimeout((int)config('recheck.connect_timeout', 5))
            ->withOptions(['allow_redirects' => false])
            ->retry(2, 2000, function (?\Throwable $exception) {
                // Laravel passes null for responses that are not failures (e.g. 3xx).
                if ($exception === null) {
                    return false;
                }
                if ($exception instanceof ConnectionException) {
                    return true;
                }
                if ($exception instanceof RequestException) {
                    return $exception->response->status() >= 500;
                }
                return false;
            }, throw: false);
    }

    /**
     * Fetch $url with the re-check client and classify the transport-level
     * outcome; $interpret only runs for a 2xx response and turns the body into
     * a RemoteCheck.
     *
     * @param callable(Response): RemoteCheck $interpret
     */
    protected function performCheck(string $url, callable $interpret): RemoteCheck
    {
        try {
            $response = $this->checkHttp()->get($url);
        } catch (ConnectionException $e) {
            $kind = preg_match('/timed? ?out|timeout|cURL error 28/i', $e->getMessage())
                ? RemoteCheck::ERROR_TIMEOUT
                : RemoteCheck::ERROR_CONNECTION;
            return RemoteCheck::error($kind, null, $e->getMessage());
        } catch (RequestException $e) {
            $response = $e->response;
        } catch (\Throwable $e) {
            return RemoteCheck::error(RemoteCheck::ERROR_CONNECTION, null, $e->getMessage());
        }

        $status = $response->status();

        if ($status >= 500) {
            return RemoteCheck::error(RemoteCheck::ERROR_HTTP_5XX, $status);
        }
        if ($status >= 400) {
            return RemoteCheck::gone($status);
        }
        if ($status >= 300) {
            $location = (string)$response->header('Location');
            $kind = str_contains($location, 'returnUrl') || str_contains($location, 'turnstile')
                ? RemoteCheck::ERROR_BOT_CHECK
                : RemoteCheck::ERROR_REDIRECT;
            return RemoteCheck::error($kind, $status, $location);
        }

        if ($this->looksLikeBotCheck($response->body())) {
            return RemoteCheck::error(RemoteCheck::ERROR_BOT_CHECK, $status);
        }

        try {
            return $interpret($response);
        } catch (\Throwable $e) {
            return RemoteCheck::error(RemoteCheck::ERROR_UNPARSEABLE, $status, $e->getMessage());
        }
    }

    /**
     * Cloudflare Turnstile / challenge interstitials. Note that ordinary
     * adr.rik.ee pages carry Cloudflare's passive "challenge-platform" beacon,
     * so that string must not be used here.
     */
    protected function looksLikeBotCheck(string $body): bool
    {
        $needle = mb_substr($body, 0, 20000);
        return str_contains($needle, 'turnstile')
            || str_contains($needle, 'robotkontroll')
            || str_contains($needle, 'cf-chl-')
            || str_contains($needle, 'Just a moment...');
    }
}
