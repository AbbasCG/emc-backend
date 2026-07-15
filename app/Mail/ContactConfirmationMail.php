<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactConfirmationMail extends Mailable
{
    use SerializesModels;


    public function __construct(
        public readonly string  $visitorName,
        public readonly string  $subjectLine,
        public readonly string  $body,
        public readonly string  $submittedAt,
        public readonly string  $ticketNumber = '',
    ) {
        // Route all Contact Form emails through the dedicated support mailer
        // (support_smtp → smtp failover) so they originate from support@edumc.nl.
        $this->mailer('support');
    }

    public function envelope(): Envelope
    {
        $supportEmail = (string) config('mail.support_email', 'support@edumc.nl');
        $supportName  = (string) config('mail.support_name', 'EMC Support');

        return new Envelope(
            from:    new Address(
                env('SUPPORT_MAIL_FROM_ADDRESS', $supportEmail),
                env('SUPPORT_MAIL_FROM_NAME',    $supportName),
            ),
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
