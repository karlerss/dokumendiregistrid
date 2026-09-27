<?php

namespace App\Lib\Pii;

use App\Models\Document;
use App\Models\File;
use App\Models\PiiRedaction;
use App\Models\Signature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Executes a redaction plan (pii_plan.md §6) in two steps:
 *
 *  - text: every stored string the site shows (file text, HTML, file names,
 *    title, addressee, responsible, signatures, AI summary) and the FTS
 *    index, in one transaction, verified afterwards; public links to files
 *    with a non-KEEP action are switched off at once.
 *  - files: the originals, per the plan's file actions: true PDF redaction,
 *    office→PDF conversion + redaction, text rewrite, or withholding. The
 *    original always ends up in the private bucket.
 *
 * Every prior value is stored on the redaction row so revert() can undo it.
 */
class Redactor
{
    public function __construct(private PdfRedactor $pdf)
    {
    }

    // ----------------------------------------------------------------- text

    public function applyText(PiiRedaction $redaction): bool
    {
        $document = $redaction->document()->with(['files.signatures'])->first();
        if (!$document) {
            $redaction->text_status = PiiRedaction::STATUS_FAILED;
            $redaction->appendLog('text', 'failed', ['error' => 'document deleted']);
            $redaction->save();
            return false;
        }

        $plan = $redaction->plan;
        $replacements = $plan['replacements'] ?? [];
        $fileActions = $plan['files'] ?? [];

        $this->snapshotDatabaseOnce($redaction);

        try {
            DB::transaction(function () use ($document, $redaction, $plan, $replacements, $fileActions) {
                $before = ['document' => [], 'files' => [], 'signatures' => []];

                foreach (['title', 'to', 'responsible', 'ai_title', 'ai_summary', 'file_contents', 'redacted_at'] as $col) {
                    $before['document'][$col] = $document->getRawOriginal($col);
                }
                $document->title = RedactionPlanner::replaceAll($document->title, $replacements);
                $document->to = RedactionPlanner::replaceAll($document->to, $replacements);
                $document->responsible = RedactionPlanner::replaceAll($document->responsible, $replacements);
                if (!empty($plan['clear_ai_summary'])) {
                    $document->ai_title = null;
                    $document->ai_summary = null;
                }
                $document->redacted_at = now();

                foreach ($document->files as $file) {
                    /** @var File $file */
                    $before['files'][$file->id] = [
                        'name' => $file->name, 'contents' => $file->contents, 'html' => $file->html,
                        'original_withheld' => (bool)$file->original_withheld, 'redacted_at' => $file->redacted_at?->toIso8601String(),
                    ];
                    $file->name = RedactionPlanner::replaceAll($file->name, $replacements);
                    $file->contents = RedactionPlanner::replaceAll($file->contents, $replacements);
                    $file->html = $this->redactHtml($file->html, $file->contents, $replacements);
                    $action = $fileActions[(string)$file->id]['action'] ?? RedactionPlanner::ACTION_KEEP;
                    if ($action !== RedactionPlanner::ACTION_KEEP) {
                        // Link off immediately; the file step fills redacted_location later.
                        $file->original_withheld = true;
                    }
                    $file->redacted_at = now();
                    $file->save();

                    foreach ($file->signatures as $sig) {
                        /** @var Signature $sig */
                        $before['signatures'][$sig->id] = ['name' => $sig->name, 'pno' => $sig->pno];
                        $sig->name = RedactionPlanner::replaceAll($sig->name, $replacements);
                        $sig->pno = RedactionPlanner::replaceAll($sig->pno, $replacements);
                        $sig->save();
                    }
                }

                $document->save();
                $document->ftsIndexSingle();
                $document->touch();

                // Verify on a fresh read: none of the removed strings may remain.
                $fresh = Document::query()->with('files.signatures')->findOrFail($document->id);
                $grounding = Grounding::forDocument($fresh);
                $leftover = [];
                $labels = array_map(fn($r) => $r['replacement'], $replacements);
                foreach ($replacements as $r) {
                    if (in_array($r['surface'], $labels, true)) {
                        continue; // a form that equals a label (e.g. initials in metadata)
                    }
                    $in = $grounding->find($r['surface']);
                    if ($in) {
                        $leftover[] = ['surface' => $r['surface'], 'in' => $in];
                    }
                }
                if ($leftover) {
                    throw new LeftoverException($leftover);
                }

                $redaction->before = $before;
                $redaction->text_status = PiiRedaction::STATUS_APPLIED;
                $redaction->applied_at = now();
                $redaction->appendLog('text', 'applied', ['replacements' => count($replacements)]);
                $redaction->save();
            });
        } catch (LeftoverException $e) {
            $redaction->text_status = PiiRedaction::STATUS_FAILED;
            $redaction->files_status = PiiRedaction::STATUS_SKIPPED;
            $redaction->appendLog('text', 'failed', ['leftover' => $e->leftover]);
            $redaction->save();
            return false;
        } catch (\Throwable $e) {
            $redaction->text_status = PiiRedaction::STATUS_FAILED;
            $redaction->files_status = PiiRedaction::STATUS_SKIPPED;
            $redaction->appendLog('text', 'failed', ['error' => mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 1000)]);
            $redaction->save();
            return false;
        }
        return true;
    }

    /**
     * Replace inside the HTML; if a removed form still shows in the stripped
     * text (PDFBox splits words across tags), regenerate the HTML from the
     * already-redacted plain text instead.
     */
    private function redactHtml(?string $html, ?string $redactedContents, array $replacements): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }
        $out = RedactionPlanner::replaceAll($html, $replacements);
        $g = new Grounding(['h' => Grounding::stripHtml($out)]);
        foreach ($replacements as $r) {
            if ($g->occurs($r['surface'])) {
                return self::htmlFromText($redactedContents);
            }
        }
        return $out;
    }

    public static function htmlFromText(?string $text): string
    {
        $paras = preg_split('/\n\s*\n/', trim((string)$text)) ?: [];
        return implode("\n", array_map(fn($p) => '<p>' . nl2br(e(trim($p))) . '</p>', array_filter($paras, fn($p) => trim($p) !== '')));
    }

    // ---------------------------------------------------------------- files

    public function applyFiles(PiiRedaction $redaction): void
    {
        $document = $redaction->document()->with('files')->first();
        if (!$document || $redaction->text_status !== PiiRedaction::STATUS_APPLIED) {
            $redaction->files_status = PiiRedaction::STATUS_SKIPPED;
            $redaction->save();
            return;
        }
        $plan = $redaction->plan;
        $actions = $plan['files'] ?? [];
        $forms = array_map(fn($r) => $r['surface'], $plan['replacements'] ?? []);
        $replacements = $plan['replacements'] ?? [];

        $ok = 0;
        $failed = 0;
        $todo = 0;
        foreach ($document->files as $file) {
            $action = $actions[(string)$file->id]['action'] ?? RedactionPlanner::ACTION_KEEP;
            if ($action === RedactionPlanner::ACTION_KEEP) {
                continue;
            }
            $todo++;
            if ($file->original_private_location && ($file->redacted_location || $action === RedactionPlanner::ACTION_WITHHOLD)) {
                $ok++; // already done (job retried)
                continue;
            }
            try {
                $extra = match ($action) {
                    RedactionPlanner::ACTION_REDACT_PDF => $this->redactPdfFile($file, $forms, false),
                    RedactionPlanner::ACTION_CONVERT_AND_REDACT => $this->redactPdfFile($file, $forms, true),
                    RedactionPlanner::ACTION_REWRITE_TEXT => $this->rewriteText($file, $replacements),
                    default => [],
                };
                $this->withholdOriginal($file);
                $redaction->appendLog('file', 'applied', array_merge(['file_id' => $file->id, 'action' => $action], $extra));
                $ok++;
            } catch (\Throwable $e) {
                $redaction->appendLog('file', 'failed', ['file_id' => $file->id, 'action' => $action, 'error' => mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 1000)]);
                // Whatever happened, the original must not stay public.
                try {
                    $this->withholdOriginal($file);
                    $redaction->appendLog('file', 'withheld_after_failure', ['file_id' => $file->id]);
                } catch (\Throwable $e2) {
                    $redaction->appendLog('file', 'withhold_failed', ['file_id' => $file->id, 'error' => mb_substr($e2->getMessage(), 0, 500)]);
                }
                $failed++;
            }
            $redaction->save();
        }

        $redaction->files_status = match (true) {
            $todo === 0 => PiiRedaction::STATUS_SKIPPED,
            $failed === 0 => PiiRedaction::STATUS_APPLIED,
            $ok > 0 => PiiRedaction::STATUS_PARTIAL,
            default => PiiRedaction::STATUS_FAILED,
        };
        $redaction->save();
    }

    /** @return array{hits: array, pages: int, redacted_location: string} */
    private function redactPdfFile(File $file, array $forms, bool $convertFirst): array
    {
        $dir = $this->tempDir();
        try {
            $src = $dir . '/' . $this->safeBasename($file->location);
            file_put_contents($src, Storage::disk(config('pii.public_disk'))->get($file->location));
            if ($convertFirst) {
                $src = $this->pdf->convertToPdf($src, $dir);
            }
            $out = $dir . '/redacted.pdf';
            $result = $this->pdf->redact($src, $out, $forms);

            $baseName = pathinfo($file->name, PATHINFO_FILENAME) ?: 'fail';
            $key = Str::random(32) . '/' . $baseName . '.pdf';
            Storage::disk(config('pii.public_disk'))->put($key, file_get_contents($out));
            $file->redacted_location = $key;
            $file->save();
            return ['hits' => $result['hits'], 'pages' => $result['pages'], 'redacted_location' => $key];
        } finally {
            $this->removeDir($dir);
        }
    }

    /** @return array{redacted_location: string} */
    private function rewriteText(File $file, array $replacements): array
    {
        $key = Str::random(32) . '/' . basename($file->name);
        Storage::disk(config('pii.public_disk'))->put($key, (string)$file->contents);
        $file->redacted_location = $key;
        $file->save();
        return ['redacted_location' => $key];
    }

    /** Copy the original to the private bucket and delete it from the public one. */
    private function withholdOriginal(File $file): void
    {
        if ($file->original_private_location) {
            return;
        }
        $public = Storage::disk(config('pii.public_disk'));
        $private = Storage::disk(config('pii.private_disk'));
        if ($public->exists($file->location)) {
            $stream = $public->readStream($file->location);
            $private->writeStream($file->location, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $public->delete($file->location);
        }
        $file->original_private_location = $file->location;
        $file->original_withheld = true;
        $file->save();
    }

    // --------------------------------------------------------------- revert

    public function revert(PiiRedaction $redaction, string $by): void
    {
        $document = $redaction->document()->with('files.signatures')->first();
        $before = $redaction->before ?? [];
        if (!$document || $redaction->isReverted()) {
            return;
        }

        DB::transaction(function () use ($document, $redaction, $before, $by) {
            foreach ($before['document'] ?? [] as $col => $val) {
                $document->{$col} = $val;
            }
            foreach ($document->files as $file) {
                $b = $before['files'][$file->id] ?? $before['files'][(string)$file->id] ?? null;
                if ($b) {
                    $file->name = $b['name'];
                    $file->contents = $b['contents'];
                    $file->html = $b['html'];
                    $file->original_withheld = (bool)$b['original_withheld'];
                    $file->redacted_at = $b['redacted_at'] ?? null;
                }
                foreach ($file->signatures as $sig) {
                    $s = $before['signatures'][$sig->id] ?? $before['signatures'][(string)$sig->id] ?? null;
                    if ($s) {
                        $sig->name = $s['name'];
                        $sig->pno = $s['pno'];
                        $sig->save();
                    }
                }
                // Move originals back and drop redacted copies.
                if ($file->original_private_location) {
                    $public = Storage::disk(config('pii.public_disk'));
                    $private = Storage::disk(config('pii.private_disk'));
                    if ($private->exists($file->original_private_location)) {
                        $stream = $private->readStream($file->original_private_location);
                        $public->writeStream($file->location, $stream);
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                        $private->delete($file->original_private_location);
                    }
                    $file->original_private_location = null;
                }
                if ($file->redacted_location) {
                    Storage::disk(config('pii.public_disk'))->delete($file->redacted_location);
                    $file->redacted_location = null;
                }
                $file->save();
            }
            $document->save();
            $document->ftsIndexSingle();
            $document->touch();

            $redaction->reverted_at = now();
            $redaction->reverted_by = $by;
            $redaction->appendLog('revert', 'applied', []);
            $redaction->save();
        });
    }

    // -------------------------------------------------------------- helpers

    /**
     * One SQLite snapshot per day before the first redaction, so a bad
     * plan can be undone even if revert() is not enough. Best effort.
     */
    private function snapshotDatabaseOnce(PiiRedaction $redaction): void
    {
        if (app()->runningUnitTests() || DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }
        $key = 'pii.snapshot_on';
        $today = now()->toDateString();
        if (\Illuminate\Support\Facades\Cache::get($key) === $today) {
            return;
        }
        try {
            $dir = storage_path('backups');
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $path = $dir . '/pii-' . $today . '.sqlite';
            if (!file_exists($path)) {
                DB::statement("VACUUM INTO " . DB::getPdo()->quote($path));
            }
            \Illuminate\Support\Facades\Cache::forever($key, $today);
            $redaction->appendLog('snapshot', 'ok', ['path' => $path]);
        } catch (\Throwable $e) {
            $redaction->appendLog('snapshot', 'failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    private function tempDir(): string
    {
        $dir = storage_path('temp/pii_' . Str::random(12));
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create $dir");
        }
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    private function safeBasename(string $location): string
    {
        $name = basename($location);
        return preg_replace('/[^\w.\-]+/u', '_', $name) ?: 'file';
    }
}

class LeftoverException extends \RuntimeException
{
    public function __construct(public array $leftover)
    {
        parent::__construct('Removed strings still present after redaction');
    }
}
