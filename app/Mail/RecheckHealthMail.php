<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecheckHealthMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param string[] $problems
     */
    public function __construct(public array $problems, public bool $recovered)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->recovered
                ? 'Dokumendikontroll: daemon töötab jälle'
                : 'Dokumendikontroll: daemon vajab tähelepanu',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.recheck.health',
            with: [
                'problems' => $this->problems,
                'recovered' => $this->recovered,
                'url' => route('recheck.index'),
            ],
        );
    }
}
