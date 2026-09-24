<?php

namespace App\Console\Commands;

use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repair for probes that were recorded as "gone" on an HTTP status that does
 * not mean the document is missing (429 rate limit, 403 forbidden). Deletes
 * the unreviewed queue rows and puts the documents back to "never checked" so
 * the daemon probes them again first.
 */
class RecheckResetFalseGone extends Command
{
    protected $signature = 'app:recheck-reset-false-gone
        {--http-status=* : Statuses to treat as false "gone" (default 429 and 403)}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Undo "gone" verdicts that were recorded on a rate-limit or forbidden response and re-queue those documents';

    public function handle(): int
    {
        $statuses = array_map('intval', $this->option('http-status') ?: [429, 403]);
        $dryRun = (bool)$this->option('dry-run');

        $rows = DocumentStatusChange::query()
            ->where('to_status', 'gone')
            ->whereIn('http_status', $statuses);

        $unreviewed = (clone $rows)->unacknowledged();
        $reviewedCount = (clone $rows)->whereNotNull('acknowledged_at')->count();
        $documentIds = (clone $unreviewed)->distinct()->pluck('document_id');

        // Only states that still carry the bad verdict: a later successful
        // probe (any other status) has already superseded it.
        $states = DocumentRemoteState::query()
            ->whereIn('document_id', $documentIds)
            ->whereIn('last_http_status', $statuses);

        $this->info(sprintf(
            '%d unreviewed "gone" row(s) on HTTP %s across %d document(s); %d state row(s) to reset; %d reviewed row(s) left untouched.',
            (clone $unreviewed)->count(),
            implode('/', $statuses),
            $documentIds->count(),
            (clone $states)->count(),
            $reviewedCount,
        ));

        if ($dryRun) {
            $this->line('Dry run: nothing written.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($unreviewed, $states) {
            $states->update([
                'remote_status' => null,
                'remote_restriction' => null,
                'remote_restriction_basis' => null,
                'remote_restriction_change_basis' => null,
                'personal_data_restriction' => false,
                'checked_at' => null,
                'next_check_at' => null,
                'check_error_count' => 0,
                'last_http_status' => null,
                'updated_at' => now(),
            ]);
            $unreviewed->delete();
        });

        $this->info('Done.');
        return self::SUCCESS;
    }
}
