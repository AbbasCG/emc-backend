<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CourseCompletionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string  $studentName,
        public readonly string  $courseTitle,
        public readonly string  $completionDate,
        public readonly ?string $certificateUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "تهانينا! أتممت الدورة: {$this->courseTitle}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.course-completion');
    }
}
