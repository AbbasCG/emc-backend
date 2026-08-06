<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountCreatedMail extends Mailable
{
    use SerializesModels;


    public function __construct(
        public readonly string  $userName,
        public readonly string  $userEmail,
        public readonly ?string $temporaryPassword = null,
        public readonly ?string $loginUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'مرحباً بك في منصة EMC');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-created',
            with: [
                'userName'          => $this->userName,
                'userEmail'         => $this->userEmail,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl'          => $this->loginUrl ?? rtrim(config('app.frontend_url', config('app.url')), '/') . '/login',
            ],
        );
    }
}
