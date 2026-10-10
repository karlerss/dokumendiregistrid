<?php

namespace App\Http\Controllers;

use App\Lib\Compliance\ReviewQueue;
use App\Models\ReviewItem;
use App\Lib\Compliance\DecisionException;
use App\Lib\Compliance\AdminActions;
use App\Mail\TakedownAcceptedMail;
use App\Mail\TakedownAcceptedRemovedMail;
use App\Mail\TakedownDeniedMail;
use App\Mail\TakedownVerifiedAdminMail;
use App\Mail\TakedownVerificationMail;
use App\Models\Document;
use App\Models\TakedownRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class TakedownController extends Controller
{
    private const RESEND_DECAY_SECONDS = 60;

    public function store(Request $request, Document $document)
    {
        $validated = $request->validate([
            'author_name' => 'required|string|max:255',
            'author_email' => 'required|email|max:255',
            'legal_basis' => 'required|string|in:' . implode(',', TakedownRequest::LEGAL_BASES),
            'objection_note' => 'nullable|string|max:5000',
        ]);

        $takedownRequest = $document->takedownRequests()->create([
            'original_document_url' => route('document', [
                'document' => $document->id,
                'slug' => Str::slug($document->title),
            ]),
            'status' => TakedownRequest::STATUS_UNVERIFIED,
            'author_name' => $validated['author_name'],
            'author_email' => $validated['author_email'],
            'legal_basis' => $validated['legal_basis'],
            'objection_note' => $validated['objection_note'] ?? null,
            'ip' => $request->ip(),
        ]);

        $this->sendVerificationCode($takedownRequest);

        return redirect()->route('takedowns.track', $takedownRequest)
            ->with('success', 'Saatsime Teie e-posti aadressile kinnituskoodi. Sisestage see allpool, et taotlus kinnitada.');
    }

    public function track(TakedownRequest $takedownRequest)
    {
        $takedownRequest->load('document');

        return view('takedowns.track', [
            'takedownRequest' => $takedownRequest,
        ]);
    }

    public function verify(Request $request, TakedownRequest $takedownRequest)
    {
        if ($takedownRequest->status !== TakedownRequest::STATUS_UNVERIFIED) {
            return redirect()->route('takedowns.track', $takedownRequest);
        }

        $validated = $request->validate([
            'verification_code' => 'required|string|size:6',
        ]);

        if (!$takedownRequest->verificationCodeMatches($validated['verification_code'])) {
            return back()->withErrors([
                'verification_code' => 'Kinnituskood on vale või aegunud. Palun proovige uuesti.',
            ]);
        }

        $takedownRequest->markVerified();
        try {
            app(ReviewQueue::class)->flagTakedown($takedownRequest);
        } catch (\Throwable $e) {
            report($e);
        }
        $this->notifyAdminAboutVerifiedRequest($takedownRequest);

        return redirect()->route('takedowns.track', $takedownRequest)
            ->with('success', 'Teie e-posti aadress on kinnitatud. Taotlus on nüüd ootel ja vaatame selle üle.');
    }

    public function resend(TakedownRequest $takedownRequest)
    {
        if ($takedownRequest->status !== TakedownRequest::STATUS_UNVERIFIED) {
            return redirect()->route('takedowns.track', $takedownRequest);
        }

        $key = $this->resendRateLimitKey($takedownRequest);

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);

            return redirect()->route('takedowns.track', $takedownRequest)
                ->with('error', "Uut koodi saab küsida kord minutis. Palun oodake {$seconds} sekundit.");
        }

        $this->sendVerificationCode($takedownRequest);

        return redirect()->route('takedowns.track', $takedownRequest)
            ->with('success', 'Saatsime Teie e-posti aadressile uue kinnituskoodi.');
    }

    private function sendVerificationCode(TakedownRequest $takedownRequest): void
    {
        $code = $takedownRequest->generateVerificationCode();

        RateLimiter::hit($this->resendRateLimitKey($takedownRequest), self::RESEND_DECAY_SECONDS);

        Mail::to($takedownRequest->author_email)
            ->send(new TakedownVerificationMail($takedownRequest, $code));
    }

    private function notifyAdminAboutVerifiedRequest(TakedownRequest $takedownRequest): void
    {
        $adminEmail = config('mail.admin.address');

        if (blank($adminEmail)) {
            return;
        }

        Mail::to($adminEmail)->send(new TakedownVerifiedAdminMail($takedownRequest));
    }

    private function resendRateLimitKey(TakedownRequest $takedownRequest): string
    {
        return 'takedown-resend:' . $takedownRequest->id;
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin();

        $excludePublicSector = $request->boolean('exclude_public_sector');

        $takedownRequests = TakedownRequest::query()
            ->with(['document.latestPiiAssessment.extraction.subjects', 'document.latestPiiExtraction'])
            ->when($excludePublicSector, fn($q) => $q->excludingPublicSector())
            ->orderByRaw("case when status in ('unverified', 'pending') then 0 else 1 end")
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        $total = TakedownRequest::query()->count();
        $privateCount = TakedownRequest::query()->excludingPublicSector()->count();

        return view('admin.takedowns.index', [
            'takedownRequests' => $takedownRequests,
            'excludePublicSector' => $excludePublicSector,
            'publicSectorCount' => $total - $privateCount,
        ]);
    }

    public function show(TakedownRequest $takedownRequest)
    {
        $this->authorizeAdmin();

        // Requests about a document are handled in the document page's admin panel.
        if ($takedownRequest->document_id && $takedownRequest->document) {
            return redirect()->to(route('document', ['document' => $takedownRequest->document_id]) . '#taotlus-' . $takedownRequest->id);
        }

        return view('admin.takedowns.show', [
            'takedownRequest' => $takedownRequest,
        ]);
    }

    /**
     * Accept a takedown request with one of:
     *  - remove: the review queue's "remove" decision (files, text, PII rows);
     *  - redact: the "redact" decision, from the PII assessment's plan or the
     *    strings given; the note tells the requester what was removed;
     *  - none:   accept with a note only; nothing published changes.
     */
    public function accept(Request $request, TakedownRequest $takedownRequest)
    {
        $this->authorizeAdmin();

        $action = $request->input('action', $request->boolean('remove_document') ? 'remove' : 'none');
        $validated = $request->validate([
            'action' => 'nullable|in:remove,redact,none',
            'resolution_note' => $action === 'remove' ? 'nullable|string|max:5000' : 'required|string|max:5000',
            'strings' => 'nullable|string|max:20000',
        ]);
        $note = $validated['resolution_note'] ?? null;
        $document = $takedownRequest->document;

        if (in_array($action, ['remove', 'redact'], true) && $document) {
            $strings = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)($validated['strings'] ?? '')))));
            try {
                app(AdminActions::class)->decide(
                    $document,
                    $action === 'remove' ? ReviewItem::DECISION_REMOVE : ReviewItem::DECISION_REDACT,
                    $note ?: "Eemaldamistaotlus rahuldati ({$takedownRequest->legal_basis}).",
                    ReviewItem::SOURCE_TAKEDOWN,
                    ReviewItem::PRIORITY_NAMED,
                    "eemaldamistaotlus #{$takedownRequest->id}",
                    $takedownRequest->legal_basis,
                    $this->takedownFacts($takedownRequest),
                    $action === 'redact' && $strings ? ['strings' => $strings] : [],
                );
            } catch (DecisionException $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }
        } elseif ($action === 'none') {
            app(AdminActions::class)->log($document, 'takedown_accepted', ['takedown_id' => $takedownRequest->id, 'note' => $note]);
        }

        $takedownRequest->update([
            'status' => TakedownRequest::STATUS_ACCEPTED,
            'resolution_note' => $action === 'remove' ? null : $note,
            'resolved_at' => now(),
        ]);

        Mail::to($takedownRequest->author_email)->send($action === 'remove'
            ? new TakedownAcceptedRemovedMail($takedownRequest)
            : new TakedownAcceptedMail($takedownRequest));

        return back()->with('success', match ($action) {
            'remove' => 'Taotlus rahuldati, dokument eemaldati ja esitajale saadeti teavitus.',
            'redact' => 'Taotlus rahuldati, isikuandmete redigeerimine on järjekorras ja esitajale saadeti teavitus.',
            default => 'Taotlus on rahuldatud ja teavitus saadetud.',
        });
    }

    public function deny(Request $request, TakedownRequest $takedownRequest)
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'resolution_note' => 'required|string|max:5000',
        ]);

        // The review queue's "keep" decision, with the reply to the requester
        // as the reason; recorded even when the item cannot take a decision.
        $document = $takedownRequest->document;
        $decided = false;
        if ($document) {
            try {
                app(AdminActions::class)->decide(
                    $document,
                    ReviewItem::DECISION_KEEP,
                    $validated['resolution_note'],
                    ReviewItem::SOURCE_TAKEDOWN,
                    ReviewItem::PRIORITY_NAMED,
                    "eemaldamistaotlus #{$takedownRequest->id}",
                    $takedownRequest->legal_basis,
                    $this->takedownFacts($takedownRequest),
                );
                $decided = true;
            } catch (DecisionException) {
            }
        }
        if (!$decided) {
            app(AdminActions::class)->log($document, 'takedown_denied', [
                'takedown_id' => $takedownRequest->id, 'note' => $validated['resolution_note'],
            ]);
        }

        $takedownRequest->update([
            'status' => TakedownRequest::STATUS_DENIED,
            'resolution_note' => $validated['resolution_note'],
            'resolved_at' => now(),
        ]);

        Mail::to($takedownRequest->author_email)->send(new TakedownDeniedMail($takedownRequest));

        return back()->with('success', 'Taotlus on tagasi lükatud ja teavitus saadetud.');
    }

    private function takedownFacts(TakedownRequest $takedownRequest): array
    {
        return $takedownRequest->reviewFacts();
    }

    private function authorizeAdmin(): void
    {
        if (!session('is_admin')) {
            abort(403);
        }
    }
}
