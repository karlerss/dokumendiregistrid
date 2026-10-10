<?php

namespace App\Console\Commands;

use App\Lib\Compliance\TakedownPiiCheck;
use App\Models\TakedownRequest;
use Illuminate\Console\Command;

/**
 * One-off: queue the PII check for documents that already have a pending
 * (e-mail confirmed) takedown request. New requests trigger it on
 * confirmation.
 */
class PiiExtractTakedowns extends Command
{
    protected $signature = 'pii:extract-takedowns {--dry-run : Count what would be queued and queue nothing}';

    protected $description = 'Queue a PII extraction for every document with a pending takedown request that has none yet';

    public function handle(TakedownPiiCheck $check): int
    {
        $takedowns = TakedownRequest::query()
            ->where('status', TakedownRequest::STATUS_PENDING)
            ->whereNotNull('document_id')
            ->with('document')
            ->get()
            ->unique('document_id');

        $queued = 0;
        $skipped = 0;
        foreach ($takedowns as $takedown) {
            if (!$takedown->document) {
                continue;
            }
            if ($this->option('dry-run')) {
                $needed = !$takedown->document->isRemoved() && !\App\Lib\Pii\Assessor::latestUsableExtraction($takedown->document);
                $needed ? $queued++ : $skipped++;
                continue;
            }
            $check->queue($takedown->document) ? $queued++ : $skipped++;
        }

        $this->info(sprintf('%s%d documents with pending takedowns: %d PII checks queued, %d already have one (or need none).',
            $this->option('dry-run') ? '[dry-run] ' : '', $takedowns->count(), $queued, $skipped));
        return self::SUCCESS;
    }
}
