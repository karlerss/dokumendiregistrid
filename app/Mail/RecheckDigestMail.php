<?php

namespace App\Mail;

use App\Models\DocumentStatusChange;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Daily summary of new review-queue rows produced by the re-check daemon.
 */
class RecheckDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public const MAX_LISTED = 50;

    public function __construct(public CarbonInterface $since)
    {
    }

    public function envelope(): Envelope
    {
        $new = DocumentStatusChange::query()->where('created_at', '>=', $this->since);
        $personal = (clone $new)->where('personal_data', true)->count();
        $total = $new->count();

        $subject = "Dokumendikontroll: $total uut muutust";
        if ($personal > 0) {
            $subject .= ", neist $personal isikuandmetega";
        }

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $changes = DocumentStatusChange::query()
            ->with(['document.organisation'])
            ->where('created_at', '>=', $this->since)
            ->orderByDesc('personal_data')
            ->orderByDesc('occurred_at')
            ->limit(self::MAX_LISTED)
            ->get();

        return new Content(
            markdown: 'emails.recheck.digest',
            with: [
                'changes' => $changes,
                'newCount' => DocumentStatusChange::query()->where('created_at', '>=', $this->since)->count(),
                'personalCount' => DocumentStatusChange::query()->where('created_at', '>=', $this->since)->where('personal_data', true)->count(),
                'pendingCount' => DocumentStatusChange::query()->unacknowledged()->count(),
                'url' => route('recheck.index'),
            ],
        );
    }
}
