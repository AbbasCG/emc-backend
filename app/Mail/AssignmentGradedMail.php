<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AssignmentGradedMail extends Mailable
{
    use SerializesModels;


    public function __construct(
        public readonly string  $studentName,
        public readonly string  $courseTitle,
        public readonly string  $assignmentTitle,
        public readonly ?float  $score,
        public readonly ?float  $maxScore,
        public readonly ?string $feedback = null,
        public readonly ?string $dashboardUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "تم تقييم واجبك: {$this->assignmentTitle}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.assignment-graded');
    }
}
