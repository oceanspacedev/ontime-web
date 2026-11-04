<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent directly (not queued) so an SMTP failure shows up on the email login page.
 */
class EmailLoginMail extends Mailable
{
    public function __construct(
        public string $name,
        public string $otp,
        public string $loginUrl,
        public int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Kode masuk '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.email-login',
        );
    }
}
