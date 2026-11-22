<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A ready-made subject + body (an automation action, a stored notification template, a platform
 * notification), sent through EmailService so it is queued, logged in Email Logs and retried like
 * every other email. Replaces the unlogged SendEmailJob path.
 */
class RawContentMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly string $subjectText,
        public readonly string $body,
        public readonly bool $isHtml = true,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectText);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->isHtml ? $this->body : nl2br(e($this->body)));
    }
}
