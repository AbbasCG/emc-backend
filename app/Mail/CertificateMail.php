<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CertificateMail extends Mailable
{
    use SerializesModels;


    public function __construct(
        public readonly string  $studentName,
        public readonly string  $courseTitle,
        public readonly ?string $certificateUrl = null,
        public readonly ?string $verificationCode = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "شهادتك جاهزة: {$this->courseTitle}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.certificate');
    }
}
