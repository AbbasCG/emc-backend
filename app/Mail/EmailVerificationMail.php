<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Asks a new user to prove they own the email address of their account.
 *
 * Carries only the greeting name, the signed verification URL and the human text of
 * how long it stays valid. The URL is the single secret in the message.
 */
class EmailVerificationMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly string $userName,
        public readonly string $verifyUrl,
        public readonly string $expiresText,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'توثيق البريد الإلكتروني لحسابك في EMC');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.email-verification');
    }
}
