<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LearningPathCompletionMail extends Mailable
{
    use SerializesModels;


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
