<?php

namespace App\Mail;

use App\Models\TakedownRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TakedownVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public TakedownRequest $takedownRequest,
        public string $code,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Kinnitage oma eemaldamistaotlus',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.takedown.verification',
            with: [
                'takedownRequest' => $this->takedownRequest,
                'code' => $this->code,
                'url' => route('takedowns.track', $this->takedownRequest),
            ],
        );
    }
}
