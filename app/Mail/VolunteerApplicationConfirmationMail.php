<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VolunteerApplicationConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $applicantName,
        public readonly string $department,
        public readonly string $submittedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تم استلام طلب التطوع — منصة EMC');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.volunteer-confirmation',
            with: [
                'applicantName' => $this->applicantName,
                'department'    => $this->department,
                'submittedAt'   => $this->submittedAt,
            ],
        );
    }
}
