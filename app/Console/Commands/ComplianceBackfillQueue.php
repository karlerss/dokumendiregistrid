<?php

namespace App\Console\Commands;

use App\Lib\Compliance\ReviewQueue;
use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
use App\Models\PiiAssessment;
use App\Models\ReviewItem;
use App\Models\TakedownRequest;
use Illuminate\Console\Command;

/**
 * One-off: put the backlog that predates the review queue into it. New
 * entries arrive on their own (daemon, takedown verification, PII
 * assessment); this covers what was already waiting:
 *
 *  - unacknowledged status changes to "restricted" or "gone";
 *  - pending (verified) takedown requests;
 *  - unreviewed HIGH PII assessments;
 *  - hidden documents whose files are still publicly downloadable.
 *
 * Only flags: nothing published changes. Safe to re-run (flags dedupe).
 */
class ComplianceBackfillQueue extends Command
{
    protected $signature = 'compliance:backfill-queue {--dry-run : Count what would be queued and write nothing}';

    protected $description = 'Queue the existing backlog (status changes, takedowns, HIGH PII assessments, hidden documents with public files) for review';

    public function handle(ReviewQueue $queue): int
    {
        $dryRun = (bool)$this->option('dry-run');
        $before = ReviewItem::count();

        $changes = DocumentStatusChange::query()
            ->whereNull('acknowledged_at')
            ->whereIn('to_status', [DocumentRemoteState::STATUS_RESTRICTED, DocumentRemoteState::STATUS_GONE])
            ->whereHas('document');
        $takedowns = TakedownRequest::query()->where('status', TakedownRequest::STATUS_PENDING)->whereNotNull('document_id');
        $assessments = PiiAssessment::query()->where('band', PiiAssessment::BAND_HIGH)->whereNull('reviewed_at')->whereHas('document');
        $hidden = Document::query()
            ->where('visible', false)
            ->whereNull('removed_at')
            ->whereExists(fn($q) => $q->selectRaw('1')->from('files')
                ->whereColumn('files.document_id', 'documents.id')
                ->where('files.original_withheld', false)
                ->where('files.location', '!=', ''));

        $this->table(['Source', 'Rows'], [
            ['status changes (restricted/gone, unacknowledged)', $changes->count()],
            ['pending takedown requests', $takedowns->count()],
            ['unreviewed HIGH PII assessments', $assessments->count()],
            ['hidden documents with public files', $hidden->count()],
        ]);
        if ($dryRun) {
            $this->info('Dry run: nothing written.');
            return self::SUCCESS;
        }

        $changes->with('document')->chunkById(500, function ($rows) use ($queue) {
            foreach ($rows as $change) {
                $queue->flagStatusChange($change);
            }
            $this->output->write('.');
        });
        foreach ($takedowns->with('document')->get() as $takedown) {
            $queue->flagTakedown($takedown);
        }
        $assessments->with('document')->chunkById(200, function ($rows) use ($queue) {
            foreach ($rows as $assessment) {
                $queue->flagAssessment($assessment);
            }
        });
        $hidden->chunkById(200, function ($rows) use ($queue) {
            foreach ($rows as $document) {
                $queue->flagHiddenWithPublicFiles($document);
            }
        });

        $this->newLine();
        $open = ReviewItem::open()->count();
        $this->info(sprintf('Review items: %d before, %d now (%d open).', $before, ReviewItem::count(), $open));
        return self::SUCCESS;
    }
}
