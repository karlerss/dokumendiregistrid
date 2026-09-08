<?php

namespace App\Http\Controllers;

use App\Lib\Recheck\Heartbeat;
use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
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
            ->with(['document.organisation', 'document.remoteState'])
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

        return view('admin.recheck.index', [
            'changes' => $changes,
            'counts' => $counts,
            'queue' => $queue,
            'type' => $type,
            'showAll' => $showAll,
            'heartbeat' => Heartbeat::lastBeat(),
            'heartbeatStale' => Heartbeat::isStale(),
            'stats' => Heartbeat::statsForLastHours(24),
            'pausedHosts' => Heartbeat::pausedHosts(),
            'hiddenCount' => Document::query()->where('visible', false)->count(),
        ]);
    }

    public function acknowledge(DocumentStatusChange $change)
    {
        $this->authorizeAdmin();
        $change->acknowledge();
        return back()->with('success', 'Muutus märgiti läbivaadatuks.');
    }

    public function ignore(DocumentStatusChange $change)
    {
        $this->authorizeAdmin();
        $change->acknowledge(DocumentStatusChange::ACTION_IGNORED);
        return back()->with('success', 'Muutus ignoreeritud.');
    }

    public function hide(DocumentStatusChange $change)
    {
        $this->authorizeAdmin();
        $document = $change->document;
        if ($document) {
            $document->update(['visible' => false]);
        }
        $change->acknowledge(DocumentStatusChange::ACTION_HIDDEN);
        return back()->with('success', 'Dokument peideti avalikust vaatest.');
    }

    public function unhide(DocumentStatusChange $change)
    {
        $this->authorizeAdmin();
        $document = $change->document;
        if ($document) {
            $document->update(['visible' => true]);
        }
        $change->acknowledge(DocumentStatusChange::ACTION_UNHIDDEN);
        return back()->with('success', 'Dokument on jälle nähtav.');
    }

    /**
     * Remove the stored files (database rows and remote objects) but keep the
     * metadata row, so the fetchers' URL dedupe prevents re-ingestion.
     */
    public function deleteFiles(DocumentStatusChange $change)
    {
        $this->authorizeAdmin();
        $document = $change->document;
        if ($document) {
            DB::transaction(function () use ($document) {
                $document->deleteFiles();
                $document->update(['visible' => false]);
                $document->ftsIndexSingle();
            });
        }
        $change->acknowledge(DocumentStatusChange::ACTION_FILES_DELETED);
        return back()->with('success', 'Failid kustutati ja dokument peideti.');
    }

    /**
     * A document that became public again: download its files afresh.
     */
    public function refetch(DocumentStatusChange $change)
    {
        $this->authorizeAdmin();
        $document = $change->document;
        if ($document) {
            if ($document->organisation->fetcher_type !== 'delta-adr') {
                return back()->with('error', 'Uuesti laadimine on praegu võimalik ainult ADR registrite dokumentidele.');
            }
            $document->reindex();
            $document->update(['visible' => true]);
        }
        $change->acknowledge(DocumentStatusChange::ACTION_REFETCHED);
        return back()->with('success', 'Failid laeti uuesti.');
    }

    public function acknowledgeAll(Request $request)
    {
        $this->authorizeAdmin();
        $type = $request->get('type');

        $count = DocumentStatusChange::query()
            ->unacknowledged()
            ->when($type === 'personal', fn($q) => $q->where('personal_data', true))
            ->when(in_array($type, ['restricted', 'gone', 'public'], true), fn($q) => $q->where('to_status', $type))
            ->update(['acknowledged_at' => now(), 'updated_at' => now()]);

        return redirect()->route('recheck.index', array_filter(['type' => $type]))
            ->with('success', "$count muutust märgiti läbivaadatuks.");
    }

    private function authorizeAdmin(): void
    {
        if (!session('is_admin')) {
            abort(403);
        }
    }
}
