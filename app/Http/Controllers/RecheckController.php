<?php

namespace App\Http\Controllers;

use App\Lib\Recheck\Heartbeat;
use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
use App\Lib\Compliance\AdminActions;
use App\Lib\Compliance\DecisionException;
use App\Models\ComplianceAction;
use App\Models\ReviewItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin review queue and health page for the re-check daemon.
 */
class RecheckController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAdmin();

        $type = $request->get('type');
        $showAll = $request->boolean('all');

        $changes = DocumentStatusChange::query()
            ->with(['document.organisation', 'document.remoteState', 'document.latestPiiAssessment.extraction.subjects', 'document.latestPiiExtraction'])
            ->when(!$showAll, fn($q) => $q->unacknowledged())
            ->when($type === 'personal', fn($q) => $q->where('personal_data', true))
            ->when(in_array($type, ['restricted', 'gone', 'public'], true), fn($q) => $q->where('to_status', $type))
            ->orderByDesc('personal_data')
            ->orderByDesc('occurred_at')
            ->paginate(50)
            ->withQueryString();

        $counts = DocumentStatusChange::query()
            ->unacknowledged()
            ->selectRaw("count(*) as total, sum(personal_data) as personal, sum(to_status = 'restricted') as restricted, sum(to_status = 'gone') as gone, sum(to_status = 'public') as public")
            ->first();

        $queue = DocumentRemoteState::query()
            ->selectRaw("count(*) as total, sum(remote_status is null) as unchecked, sum(next_check_at is null or next_check_at <= ?) as due, sum(remote_status = 'public') as public, sum(remote_status = 'restricted') as restricted, sum(remote_status = 'gone') as gone, sum(check_error_count > 0) as erroring", [now()])
            ->first();

        // Due documents the daemon cannot reach right now, per paused host, so
        // the "pending" figure separates a blocked backlog from real work.
        $pausedHosts = Heartbeat::pausedHosts();
        $pausedBacklog = [];
        foreach (array_keys($pausedHosts) as $host) {
            $pausedBacklog[$host] = DocumentRemoteState::query()
                ->join('documents', 'documents.id', '=', 'document_remote_states.document_id')
                ->due()
                ->where('documents.url', 'like', '%://' . $host . '/%')
                ->count();
        }
        $dueActive = max(0, (int)($queue->due ?? 0) - array_sum($pausedBacklog));

        return view('admin.recheck.index', [
            'changes' => $changes,
            'counts' => $counts,
            'queue' => $queue,
            'type' => $type,
            'showAll' => $showAll,
            'heartbeat' => Heartbeat::lastBeat(),
            'heartbeatStale' => Heartbeat::isStale(),
            'stats' => Heartbeat::statsForLastHours(24),
            'pausedHosts' => $pausedHosts,
            'pausedBacklog' => $pausedBacklog,
            'dueActive' => $dueActive,
            'hiddenCount' => Document::query()->where('visible', false)->count(),
        ]);
    }

    public function acknowledge(DocumentStatusChange $change, AdminActions $admin)
    {
        $this->authorizeAdmin();
        $change->acknowledge();
        $admin->log($change->document, 'status_change_acknowledged', $this->changeFacts($change));
        return back()->with('success', 'Muutus märgiti läbivaadatuks.');
    }

    public function ignore(DocumentStatusChange $change, AdminActions $admin)
    {
        $this->authorizeAdmin();
        $change->acknowledge(DocumentStatusChange::ACTION_IGNORED);
        $admin->log($change->document, 'status_change_ignored', $this->changeFacts($change));
        return back()->with('success', 'Muutus ignoreeritud.');
    }

    public function hide(DocumentStatusChange $change, AdminActions $admin)
    {
        $this->authorizeAdmin();
        $document = $change->document;
        if ($document) {
            $document->update(['visible' => false]);
            $admin->log($document, 'hidden', $this->changeFacts($change));
        }
        $change->acknowledge(DocumentStatusChange::ACTION_HIDDEN);
        return back()->with('success', 'Dokument peideti avalikust vaatest.');
    }

    public function unhide(DocumentStatusChange $change, AdminActions $admin)
    {
        $this->authorizeAdmin();
        $document = $change->document;
        if ($document) {
            if ($document->isRemoved()) {
                return back()->with('error', 'Dokument on eemaldatud; seda ei saa enam näidata.');
            }
            $document->update(['visible' => true]);
            $admin->log($document, 'unhidden', $this->changeFacts($change));
        }
        $change->acknowledge(DocumentStatusChange::ACTION_UNHIDDEN);
        return back()->with('success', 'Dokument on jälle nähtav.');
    }

    /**
     * Remove the document (the review queue's "remove" decision): every stored
     * copy of its files, its text and PII rows go; an identifying row stays so
     * the fetchers do not re-ingest it. Acknowledges the change.
     */
    public function deleteFiles(Request $request, DocumentStatusChange $change, AdminActions $admin)
    {
        $this->authorizeAdmin();
        $document = $change->document;
        if (!$document) {
            $change->acknowledge(DocumentStatusChange::ACTION_REMOVED);
            return back()->with('success', 'Dokumenti enam pole; muutus märgiti läbivaadatuks.');
        }

        $reason = trim((string)$request->input('reason')) ?: 'Allikas: ' . $change->transitionLabel()
            . ($change->basis ? " ({$change->basis})" : '') . '. Eemaldatud /haldus/kontroll kaudu.';
        try {
            $admin->decide(
                $document,
                ReviewItem::DECISION_REMOVE,
                $reason,
                ReviewItem::SOURCE_RECHECK,
                $this->priority($change),
                $request->input('reference'),
                $change->transitionLabel(),
                ['status_change_id' => $change->id, 'personal_data' => (bool)$change->personal_data],
            );
        } catch (DecisionException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Dokument eemaldati: failid, tekst ja isikuandmete read kustutati.');
    }

    /**
     * A document that became public again: download its files afresh.
     */
    public function refetch(DocumentStatusChange $change, AdminActions $admin)
    {
        $this->authorizeAdmin();
        $document = $change->document;
        if ($document) {
            if ($document->isRemoved()) {
                return back()->with('error', 'Dokument on eemaldatud; seda ei saa uuesti laadida.');
            }
            if ($document->organisation->fetcher_type !== 'delta-adr') {
                return back()->with('error', 'Uuesti laadimine on praegu võimalik ainult ADR registrite dokumentidele.');
            }
            $document->reindex();
            $document->update(['visible' => true]);
            $admin->log($document, 'refetched', $this->changeFacts($change));
        }
        $change->acknowledge(DocumentStatusChange::ACTION_REFETCHED);
        return back()->with('success', 'Failid laeti uuesti.');
    }

    public function acknowledgeAll(Request $request)
    {
        $this->authorizeAdmin();
        $type = $request->get('type');

        $query = DocumentStatusChange::query()
            ->unacknowledged()
            ->when($type === 'personal', fn($q) => $q->where('personal_data', true))
            ->when(in_array($type, ['restricted', 'gone', 'public'], true), fn($q) => $q->where('to_status', $type));

        // Log one row per change, so the record shows each document reviewed.
        $count = 0;
        $query->clone()->select(['id', 'document_id', 'to_status', 'to_restriction', 'basis', 'personal_data'])
            ->chunkById(500, function ($changes) use (&$count, $type) {
                $items = ReviewItem::whereIn('document_id', $changes->pluck('document_id'))
                    ->orderBy('id')->pluck('id', 'document_id');
                $now = now();
                ComplianceAction::insert($changes->map(fn($c) => [
                    'review_item_id' => $items[$c->document_id] ?? null,
                    'document_id' => $c->document_id,
                    'effect' => 'status_change_acknowledged',
                    'actor' => AdminActions::ACTOR,
                    'result' => json_encode(['bulk' => $type ?: 'all'] + $this->changeFacts($c), JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                ])->all());
                DocumentStatusChange::whereIn('id', $changes->pluck('id'))
                    ->update(['acknowledged_at' => $now, 'updated_at' => $now]);
                $count += $changes->count();
            });

        return redirect()->route('recheck.index', array_filter(['type' => $type]))
            ->with('success', "$count muutust märgiti läbivaadatuks.");
    }

    private function changeFacts(DocumentStatusChange $change): array
    {
        return array_filter([
            'status_change_id' => $change->id,
            'to_status' => $change->to_status,
            'restriction' => $change->to_restriction,
            'basis' => $change->basis,
            'personal_data' => (bool)$change->personal_data,
        ], fn($v) => $v !== null);
    }

    private function priority(DocumentStatusChange $change): int
    {
        return match (true) {
            (bool)$change->personal_data => ReviewItem::PRIORITY_NAMED,
            $change->to_status === DocumentRemoteState::STATUS_RESTRICTED => ReviewItem::PRIORITY_RESTRICTED,
            default => ReviewItem::PRIORITY_GONE,
        };
    }

    private function authorizeAdmin(): void
    {
        if (!session('is_admin')) {
            abort(403);
        }
    }
}
