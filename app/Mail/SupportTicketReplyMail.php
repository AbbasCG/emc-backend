<?php

namespace App\Mail;

use App\Mail\Concerns\UsesSupportSender;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportTicketReplyMail extends Mailable
{
    use SerializesModels;
    use UsesSupportSender;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $ticketNumber,
        public readonly string $ticketSubject,
        public readonly string $replyMessage,
    ) {}

    public function envelope(): Envelope
    {
        $supportEmail = (string) config('mail.support_email', 'support@edumc.nl');
        $supportName  = (string) config('mail.support_name', 'EMC Support');

        return new Envelope(
            // Previously hardcoded From: support@edumc.nl without ever switching off
            // the default mailer — sent through the noreply SMTP login, which strict
            // providers reject as sender spoofing. Now correctly routes through the
            // 'support' mailer (or falls back safely) via UsesSupportSender.
            from:    $this->resolveSupportSender(),
            replyTo: [new Address($supportEmail, $supportName)],
            bcc:     [new Address($supportEmail, $supportName)],
            subject: "رد على تذكرتك {$this->ticketNumber} — منصة EMC",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.support-ticket-reply',
            with: [
                'recipientName' => $this->recipientName,
                'ticketNumber'  => $this->ticketNumber,
                'ticketSubject' => $this->ticketSubject,
                'replyMessage'  => $this->replyMessage,
                'supportEmail'  => (string) config('mail.support_email', 'support@edumc.nl'),
            ],
        );
    }
}
