<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to a volunteer once HR has converted their accepted request into a membership.
 *
 * $temporaryPassword is set when the account was just created (or its credentials were re-issued); it is null when the person already
 * had an account, in which case the mail tells them to keep using their existing password.
 *
 * Deliberately NOT queued and NOT serialized: a queued Mailable would write the plain password into the jobs table. It is built, sent
 * and dropped in the same request, and never persisted anywhere.
 */
class VolunteerAcceptedMail extends Mailable
{
    public function __construct(
        protected readonly string  $recipientName,
        protected readonly string  $recipientEmail,
        protected readonly string  $departmentName,
        protected readonly ?string $temporaryPassword = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تم قبولك وتم إنشاء حساب لك — ' . config('app.name'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.volunteer-accepted',
            with: [
                'recipientName'     => $this->recipientName,
                'recipientEmail'    => $this->recipientEmail,
                'departmentName'    => $this->departmentName,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl'          => rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/login',
            ],
        );
    }

    public function hasTemporaryPassword(): bool
    {
        return $this->temporaryPassword !== null;
    }
}
