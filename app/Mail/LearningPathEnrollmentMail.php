<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LearningPathEnrollmentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string  $studentName,
        public readonly string  $pathTitle,
        public readonly int     $coursesCount,
        public readonly ?string $estimatedDuration = null,
        public readonly ?string $pathUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "انضممت إلى مسار: {$this->pathTitle}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.learning-path-enrollment');
    }
}
