<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportTicketReplyMail extends Mailable
{
    use SerializesModels;


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
            from:    new Address($supportEmail, $supportName),
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
