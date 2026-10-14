<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VerificationCodeMail extends Mailable
{
    public function __construct(
        public string $name,
        public string $code,
        public string $purpose, // 'register' | 'login' | 'email_change'
        public int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'MTQ-ATTENDEASE'),
            subject: "Your MTQ-ATTENDEASE verification code: {$this->code}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.code');
    }
}
