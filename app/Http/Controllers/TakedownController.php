<?php

namespace App\Http\Controllers;

use App\Mail\TakedownAcceptedMail;
use App\Mail\TakedownAcceptedRemovedMail;
use App\Mail\TakedownDeniedMail;
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

    private function resendRateLimitKey(TakedownRequest $takedownRequest): string
    {
        return 'takedown-resend:' . $takedownRequest->id;
    }

    public function index()
    {
        $this->authorizeAdmin();

        $takedownRequests = TakedownRequest::query()
            ->with('document')
            ->orderByRaw("case when status in ('unverified', 'pending') then 0 else 1 end")
            ->orderByDesc('created_at')
            ->paginate(50);

        return view('admin.takedowns.index', [
            'takedownRequests' => $takedownRequests,
        ]);
    }

    public function show(TakedownRequest $takedownRequest)
    {
        $this->authorizeAdmin();

        $takedownRequest->load('document');

        return view('admin.takedowns.show', [
            'takedownRequest' => $takedownRequest,
        ]);
    }

    public function accept(Request $request, TakedownRequest $takedownRequest)
    {
        $this->authorizeAdmin();

        $removeDocument = $request->boolean('remove_document');

        $validated = $request->validate([
            'resolution_note' => $removeDocument ? 'nullable|string|max:5000' : 'required|string|max:5000',
        ]);

        if ($removeDocument) {
            $takedownRequest->document?->fullDelete();

            $takedownRequest->update([
                'document_id' => null,
                'status' => TakedownRequest::STATUS_ACCEPTED,
                'resolution_note' => null,
                'resolved_at' => now(),
            ]);

            Mail::to($takedownRequest->author_email)->send(new TakedownAcceptedRemovedMail($takedownRequest));
        } else {
            $takedownRequest->update([
                'status' => TakedownRequest::STATUS_ACCEPTED,
                'resolution_note' => $validated['resolution_note'],
                'resolved_at' => now(),
            ]);

            Mail::to($takedownRequest->author_email)->send(new TakedownAcceptedMail($takedownRequest));
        }

        return redirect()->route('takedowns.index')->with('success', 'Taotlus on rahuldatud ja teavitus saadetud.');
    }

    public function deny(Request $request, TakedownRequest $takedownRequest)
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'resolution_note' => 'required|string|max:5000',
        ]);

        $takedownRequest->update([
            'status' => TakedownRequest::STATUS_DENIED,
            'resolution_note' => $validated['resolution_note'],
            'resolved_at' => now(),
        ]);

        Mail::to($takedownRequest->author_email)->send(new TakedownDeniedMail($takedownRequest));

        return redirect()->route('takedowns.index')->with('success', 'Taotlus on tagasi lükatud ja teavitus saadetud.');
    }

    private function authorizeAdmin(): void
    {
        if (!session('is_admin')) {
            abort(403);
        }
    }
}
