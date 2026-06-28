<?php

namespace App\Mail;

use App\Models\TakedownRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TakedownDeniedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TakedownRequest $takedownRequest)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Teie eemaldamistaotlus lükati tagasi',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.takedown.denied',
            with: ['takedownRequest' => $this->takedownRequest],
        );
    }
}
