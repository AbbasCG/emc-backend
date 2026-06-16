<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminNewRegistrationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string  $adminName,
        public readonly string  $studentName,
        public readonly string  $studentEmail,
        public readonly string  $courseTitle,
        public readonly int     $registrationId,
        public readonly ?string $adminUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "تسجيل جديد: {$this->studentName} في {$this->courseTitle}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin-new-registration');
    }
}
