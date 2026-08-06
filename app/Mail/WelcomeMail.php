<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable
{
    use SerializesModels;


    public function __construct(
        public readonly string  $userName,
        public readonly string  $userEmail,
        public readonly ?string $tempPassword = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'مرحباً بك في منصة EMC');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.welcome');
    }
}
