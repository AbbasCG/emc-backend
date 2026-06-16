<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LearningPathCompletionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string  $studentName,
        public readonly string  $pathTitle,
        public readonly int     $coursesCompleted,
        public readonly ?string $certificateUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "تهانينا! أكملت المسار التعليمي: {$this->pathTitle}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.learning-path-completion');
    }
}
