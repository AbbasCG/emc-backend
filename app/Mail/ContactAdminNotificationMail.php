<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactAdminNotificationMail extends Mailable
{
    use SerializesModels;


    public function __construct(
        public readonly string  $visitorName,
        public readonly string  $visitorEmail,
        public readonly ?string $visitorPhone,
        public readonly string  $subjectLine,
        public readonly string  $messageBody,
        public readonly string  $category,
        public readonly string  $submittedAt,
        public readonly ?string $adminUrl = null,
        public readonly string  $ticketNumber = '',
    ) {
        // Route all Contact Form emails through the dedicated support mailer.
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
            replyTo: [new Address($this->visitorEmail, $this->visitorName)],
            subject: 'رسالة جديدة من نموذج التواصل — ' . $this->visitorName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-admin-notification',
            with: [
                'visitorName'  => $this->visitorName,
                'visitorEmail' => $this->visitorEmail,
                'visitorPhone' => $this->visitorPhone,
                'subject'      => $this->subjectLine,
                'messageBody'  => $this->messageBody,
                'category'     => $this->category,
                'submittedAt'  => $this->submittedAt,
                'ticketNumber' => $this->ticketNumber,
                'adminUrl'     => $this->adminUrl ?? rtrim(config('app.frontend_url', config('app.url')), '/') . '/dashboard/admin/support-tickets',
            ],
        );
    }
}
