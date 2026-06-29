<?php

namespace App\Mail;

use App\Models\TakedownRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TakedownVerifiedAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TakedownRequest $takedownRequest)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Uus kinnitatud eemaldamistaotlus',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.takedown.verified-admin',
            with: [
                'takedownRequest' => $this->takedownRequest,
                'url' => route('takedowns.show', $this->takedownRequest),
            ],
        );
    }
}
