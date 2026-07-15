<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AmbassadorApplicationConfirmationMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly string $applicantName,
        public readonly string $referenceNumber,
        public readonly string $submittedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تم استلام طلبك للانضمام إلى برنامج سفراء التحول الرقمي');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ambassador-confirmation',
            with: [
                'applicantName'   => $this->applicantName,
                'referenceNumber' => $this->referenceNumber,
                'submittedAt'     => $this->submittedAt,
            ],
        );
    }
}
