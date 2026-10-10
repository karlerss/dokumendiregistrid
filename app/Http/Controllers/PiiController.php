<?php

namespace App\Http\Controllers;

use App\Models\ComplianceAction;
use App\Jobs\RedactBandsBatch;
use App\Lib\Compliance\BatchRedaction;
use App\Console\Commands\PiiEnqueue;
use App\Jobs\ExtractDocumentPii;
use App\Jobs\RedactDocument;
use App\Jobs\RevertRedaction;
use App\Lib\Compliance\AdminActions;
use App\Lib\Compliance\DecisionException;
use App\Lib\Pii\Assessor;
use App\Lib\Pii\Contexts;
use App\Lib\Pii\Extractor;
use App\Lib\Pii\Flags;
use App\Lib\Pii\RedactionPlanner;
use App\Lib\Pii\Rules;
use App\Lib\Pii\Schema;
use App\Models\Document;
use App\Models\PiiAssessment;
use App\Models\PiiExtraction;
use App\Models\PiiRedaction;
use App\Models\PiiSubject;
use App\Models\ReviewItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admin pages for the PII pipeline (pii_plan.md §5). Everything that changes
 * public content or visibility happens only here, on an explicit button.
 */
class PiiController extends Controller
{
    private const ADMIN = 'admin';

    public function index(Request $request)
    {
        $this->authorizeAdmin();

        $band = $request->get('band');
        $review = $request->get('review', 'unreviewed'); // unreviewed | reviewed | all
        $orgId = $request->get('org');
        $series = $request->get('series');
        $context = $request->get('context');
        $flag = $request->get('flag');
        $matter = $request->get('matter');
        $redacted = $request->get('redacted'); // 1 | 0 | null
        $group = $request->boolean('group');

        // Latest assessment per document.
        $latestIds = PiiAssessment::query()->selectRaw('max(id)')->groupBy('document_id');

        $base = PiiAssessment::query()
            ->whereIn('pii_assessments.id', $latestIds)
            ->join('documents', 'documents.id', '=', 'pii_assessments.document_id')
            ->join('organisations', 'organisations.id', '=', 'documents.organisation_id')
            ->when($band, fn($q) => $q->where('pii_assessments.band', $band))
            ->when($review === 'unreviewed', fn($q) => $q->whereNull('pii_assessments.reviewed_at'))
            ->when($review === 'reviewed', fn($q) => $q->whereNotNull('pii_assessments.reviewed_at'))
            ->when($orgId, fn($q) => $q->where('documents.organisation_id', $orgId))
            ->when($series, fn($q) => $q->where('documents.series', $series))
            ->when($matter, fn($q) => $q->whereExists(fn($s) => $s->selectRaw('1')->from('pii_extractions')->whereColumn('pii_extractions.id', 'pii_assessments.extraction_id')->where('matter', $matter)))
            ->when($flag, fn($q) => $q->whereExists(fn($s) => $s->selectRaw('1')->from('pii_extractions')->whereColumn('pii_extractions.id', 'pii_assessments.extraction_id')->where('flags', 'like', '%"' . $flag . '"%')))
            ->when($context, fn($q) => $q->whereExists(fn($s) => $s->selectRaw('1')->from('pii_subjects')->whereColumn('pii_subjects.extraction_id', 'pii_assessments.extraction_id')->where(fn($w) => $w->where('context_override', $context)->orWhere(fn($x) => $x->whereNull('context_override')->where('context', $context)))))
            ->when($redacted === '1', fn($q) => $q->whereNotNull('documents.redacted_at'))
            ->when($redacted === '0', fn($q) => $q->whereNull('documents.redacted_at'));

        $groups = null;
        $assessments = null;
        if ($group) {
            $groups = (clone $base)
                ->selectRaw("organisations.name as org_name, documents.organisation_id, documents.series, count(*) as total, sum(pii_assessments.band = 'HIGH') as high, sum(pii_assessments.band = 'WARN') as warn, sum(pii_assessments.band = 'INFO') as info, sum(pii_assessments.reviewed_at is null) as unreviewed, sum(documents.redacted_at is not null) as redacted")
                ->groupBy('documents.organisation_id', 'organisations.name', 'documents.series')
                ->orderByDesc('high')->orderByDesc('warn')->orderByDesc('total')
                ->get();
        } else {
            $assessments = (clone $base)
                ->select('pii_assessments.*')
                ->with(['document.organisation', 'extraction.subjects', 'document.piiRedactions'])
                ->orderByRaw("case pii_assessments.band when 'HIGH' then 0 when 'WARN' then 1 else 2 end")
                ->orderByDesc('pii_assessments.id')
                ->paginate(50)
                ->withQueryString();
        }

        $bandCounts = PiiAssessment::query()
            ->whereIn('id', PiiAssessment::query()->selectRaw('max(id)')->groupBy('document_id'))
            // Only what still needs reviewing: the per-band figures count unreviewed assessments.
            ->selectRaw("sum(reviewed_at is null) as unreviewed, sum(reviewed_at is null and band = 'HIGH') as high, sum(reviewed_at is null and band = 'WARN') as warn, sum(reviewed_at is null and band = 'INFO') as info")
            ->first();

        $backlog = PiiExtraction::query()
            ->selectRaw("sum(status = 'pending') as pending, sum(status = 'failed') as failed, sum(status = 'needs_ocr') as needs_ocr, sum(status = 'too_large') as too_large, sum(status = 'done') as done, sum(input_tokens + output_tokens) as tokens_total")
            ->first();

        $failures = PiiExtraction::query()->with('document')->where('status', PiiExtraction::STATUS_FAILED)->latest('id')->limit(20)->get();
        $inProgress = PiiRedaction::query()->with('document')->whereNull('reverted_at')
            ->where(fn($q) => $q->where('text_status', PiiRedaction::STATUS_PENDING)->orWhere('files_status', PiiRedaction::STATUS_PENDING))
            ->latest('id')->limit(20)->get();
        $failedRedactions = PiiRedaction::query()->with('document')->whereNull('reverted_at')
            ->where(fn($q) => $q->where('text_status', PiiRedaction::STATUS_FAILED)->orWhereIn('files_status', [PiiRedaction::STATUS_FAILED, PiiRedaction::STATUS_PARTIAL]))
            ->latest('id')->limit(20)->get();

        $lastRun = Cache::get(PiiEnqueue::KEY_LAST_RUN);
        $lastRunAt = $lastRun ? Carbon::parse($lastRun) : null;

        return view('admin.pii.index', [
            'assessments' => $assessments,
            'groups' => $groups,
            'group' => $group,
            'bandCounts' => $bandCounts,
            'backlog' => $backlog,
            'failures' => $failures,
            'inProgress' => $inProgress,
            'failedRedactions' => $failedRedactions,
            'tokensToday' => Extractor::tokensUsedToday(),
            'cap' => (int)config('pii.daily_token_cap'),
            'capHit' => Cache::get(PiiEnqueue::KEY_CAP_HIT),
            'lastRunAt' => $lastRunAt,
            'schedulerStale' => !$lastRunAt || $lastRunAt->lt(now()->subMinutes(5)),
            'queueSize' => DB::table('jobs')->count(),
            'failedJobs' => DB::table('failed_jobs')->count(),
            'selectionRemaining' => Extractor::selectionQuery()->count(),
            'promptVersion' => Extractor::PROMPT_VERSION,
            'rulesVersion' => Rules::VERSION,
            'model' => config('pii.model'),
            'filters' => compact('band', 'review', 'orgId', 'series', 'context', 'flag', 'matter', 'redacted'),
            'organisations' => \App\Models\Organisation::query()->orderBy('name')->get(['id', 'name']),
            'contexts' => Contexts::LABELS,
            'flagLabels' => Flags::LABELS,
            'matters' => Schema::MATTERS,
        ]);
    }

    /**
     * Batch decision from the list page: redact every document whose current
     * assessment is in the chosen bands and not yet reviewed. Runs as a
     * queued job; each document gets its own recorded decision.
     */
    public function redactAll(Request $request)
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'bands' => 'required|array|min:1',
            'bands.*' => 'in:' . implode(',', BatchRedaction::BANDS),
            'reason' => 'required|string|max:2000',
            'reference' => 'nullable|string|max:255',
        ]);
        $bands = array_values(array_unique($data['bands']));

        RedactBandsBatch::dispatch($bands, trim($data['reason']), $data['reference'] ?? null);
        ComplianceAction::create([
            'effect' => 'batch_redaction_requested',
            'actor' => AdminActions::ACTOR,
            'result' => ['bands' => $bands, 'reason' => trim($data['reason']), 'reference' => $data['reference'] ?? null],
        ]);

        return back()->with('success', 'Pakett-redigeerimine (' . implode(', ', $bands) . ') on järjekorras. '
            . 'Iga dokument saab oma otsuse; seis on näha dokumentide lehtedel ja allolevas loendis.');
    }

    /** The PII detail lives in the admin panel of the document page. */
    public function show(Document $document)
    {
        $this->authorizeAdmin();
        return redirect()->to(route('document', ['document' => $document->id]) . '#isikuandmed');
    }

    /** Before/after snippets around each replacement, for the preview. */

    public function extract(Document $document)
    {
        $this->authorizeAdmin();
        $row = Extractor::request($document, PiiExtraction::REQUESTED_BY_ADMIN);
        $row->forceFill(['queued_at' => now()])->save();
        ExtractDocumentPii::dispatch($row->id);
        return back()->with('success', 'Ekstraktsioon on järjekorda pandud.');
    }

    public function retry(PiiExtraction $extraction)
    {
        $this->authorizeAdmin();
        $extraction->forceFill(['status' => PiiExtraction::STATUS_PENDING, 'attempts' => 0, 'queued_at' => now(), 'error' => null, 'requested_by' => PiiExtraction::REQUESTED_BY_ADMIN])->save();
        ExtractDocumentPii::dispatch($extraction->id);
        return back()->with('success', 'Ekstraktsioon pannakse uuesti järjekorda.');
    }

    public function override(Request $request, PiiSubject $subject)
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'context_override' => ['nullable', 'string', 'in:' . implode(',', Contexts::ALL)],
            'override_note' => ['nullable', 'string', 'max:500'],
        ]);
        $override = $data['context_override'] ?: null;
        if ($override === $subject->context) {
            $override = null; // same as the model's answer: no override needed
        }
        $subject->forceFill([
            'context_override' => $override,
            'override_note' => $data['override_note'] ?? null,
            'overridden_by' => self::ADMIN,
            'overridden_at' => now(),
        ])->save();

        // Cheap and pure: recompute right away rather than waiting for the worker.
        (new Assessor())->assess($subject->document);

        return back()->with('success', 'Kontekst salvestatud ja hinnang uuendatud.');
    }

    public function acknowledge(PiiAssessment $assessment, AdminActions $admin)
    {
        $this->authorizeAdmin();
        $assessment->review(PiiAssessment::REVIEW_ACKNOWLEDGED, self::ADMIN);
        $admin->log($assessment->document, 'pii_assessment_acknowledged', ['assessment_id' => $assessment->id, 'band' => $assessment->band]);
        return back()->with('success', 'Märgitud läbivaadatuks.');
    }

    /**
     * Apply the assessment's redaction plan: the review queue's "redact"
     * decision, so it is recorded with every other decision.
     */
    public function redact(Request $request, Document $document, AdminActions $admin)
    {
        $this->authorizeAdmin();
        $assessment = $document->latestPiiAssessment;
        $reason = trim((string)$request->input('reason'))
            ?: 'Isikuandmete hinnangu plaan rakendati /haldus/isikuandmed kaudu' . ($assessment ? " (hinnang {$assessment->band})." : '.');
        try {
            $admin->decide(
                $document,
                ReviewItem::DECISION_REDACT,
                $reason,
                ReviewItem::SOURCE_PII,
                ReviewItem::PRIORITY_PII,
                $request->input('reference'),
                'isikuandmete hinnang',
                ['assessment_id' => $assessment?->id, 'personal_data' => true],
            );
        } catch (DecisionException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Redigeerimine on järjekorda pandud. Tekst asendatakse esimesena, failid seejärel.');
    }

    /** Re-run the file step of a redaction whose text step succeeded. */
    public function retryFiles(PiiRedaction $redaction)
    {
        $this->authorizeAdmin();
        if ($redaction->isReverted() || $redaction->text_status !== PiiRedaction::STATUS_APPLIED) {
            return back()->with('error', 'Failide sammu saab korrata ainult kehtival redigeerimisel, mille tekstisamm õnnestus.');
        }
        $redaction->files_status = PiiRedaction::STATUS_PENDING;
        $redaction->appendLog('file', 'retry_requested', []);
        $redaction->save();
        app(AdminActions::class)->log($redaction->document, 'redaction_files_retried', ['redaction_id' => $redaction->id]);
        RedactDocument::dispatch($redaction->id);
        return back()->with('success', 'Failide samm pannakse uuesti järjekorda.');
    }

    public function revert(PiiRedaction $redaction)
    {
        $this->authorizeAdmin();
        if ($redaction->isReverted()) {
            return back()->with('error', 'Juba taastatud.');
        }
        RevertRedaction::dispatch($redaction->id, self::ADMIN);
        $admin = app(AdminActions::class);
        $admin->log($redaction->document, 'redaction_reverted', ['redaction_id' => $redaction->id]);
        $admin->reopen($redaction->document, ReviewItem::DECISION_REDACT, "redigeerimine #{$redaction->id} taastati");
        return back()->with('success', 'Taastamine on järjekorda pandud.');
    }

    public function hide(Document $document)
    {
        $this->authorizeAdmin();
        $document->update(['visible' => false]);
        $document->latestPiiAssessment?->review(PiiAssessment::REVIEW_HIDDEN, self::ADMIN);
        app(AdminActions::class)->log($document, 'hidden', ['via' => 'isikuandmed']);
        return back()->with('success', 'Dokument peidetud.');
    }

    public function unhide(Document $document)
    {
        $this->authorizeAdmin();
        if ($document->isRemoved()) {
            return back()->with('error', 'Dokument on eemaldatud; seda ei saa enam näidata.');
        }
        $document->update(['visible' => true]);
        app(AdminActions::class)->log($document, 'unhidden', ['via' => 'isikuandmed']);
        return back()->with('success', 'Dokument on taas nähtav.');
    }

    private function authorizeAdmin(): void
    {
        if (!session('is_admin')) {
            abort(403);
        }
    }
}
