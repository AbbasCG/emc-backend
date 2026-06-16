<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AssignmentSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string  $instructorName,
        public readonly string  $studentName,
        public readonly string  $courseTitle,
        public readonly string  $assignmentTitle,
        public readonly ?string $reviewUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "تسليم واجب جديد: {$this->assignmentTitle}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.assignment-submitted');
    }
}
