<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string  $visitorName,
        public readonly string  $subjectLine,
        public readonly string  $body,
        public readonly string  $submittedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تم استلام رسالتك — منصة EMC');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-confirmation',
            with: [
                'visitorName' => $this->visitorName,
                'subject'     => $this->subjectLine,
                'message'     => $this->body,
                'submittedAt' => $this->submittedAt,
            ],
        );
    }
}
