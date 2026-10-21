<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SecurityAlertMail extends Mailable
{
    /** @param array<string, ?string> $details */
    public function __construct(
        public string $name,
        public string $title,
        public string $body,
        public array $details = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address') ?: 'no-reply@mqcqc.ph.education', 'MTQ-ATTENDEASE'),
            subject: "Security alert: {$this->title}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.alert');
    }
}
