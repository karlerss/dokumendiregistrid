<?php

namespace App\Mail;

use App\Models\TakedownRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TakedownAcceptedRemovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TakedownRequest $takedownRequest)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Teie eemaldamistaotlus rahuldati ja dokument eemaldati',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.takedown.accepted-removed',
            with: ['takedownRequest' => $this->takedownRequest],
        );
    }
}
