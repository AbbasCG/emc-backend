<?php

namespace App\Mail;

use App\Mail\Concerns\UsesSupportSender;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactConfirmationMail extends Mailable
{
    use SerializesModels;
    use UsesSupportSender;

    public function __construct(
        public readonly string  $visitorName,
        public readonly string  $subjectLine,
        public readonly string  $body,
        public readonly string  $submittedAt,
        public readonly string  $ticketNumber = '',
    ) {
    }

    public function envelope(): Envelope
    {
        $supportEmail = (string) config('mail.support_email', 'support@edumc.nl');
        $supportName  = (string) config('mail.support_name', 'EMC Support');

        return new Envelope(
            // Sets ->mailer('support') when configured; otherwise falls back to the
            // default mailer with a matching From — see UsesSupportSender.
            from:    $this->resolveSupportSender(),
            replyTo: [new Address($supportEmail, $supportName)],
            subject: 'تم استلام رسالتك — منصة EMC',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-confirmation',
            with: [
                'visitorName'  => $this->visitorName,
                'subject'      => $this->subjectLine,
                // 'messageBody' (not 'message') — Laravel reserves $message internally;
                // passing it as 'message' causes TypeError in Blade rendering.
                'messageBody'  => $this->body,
                'submittedAt'  => $this->submittedAt,
                'ticketNumber' => $this->ticketNumber,
                'supportEmail' => (string) config('mail.support_email', 'support@edumc.nl'),
            ],
        );
    }
}
