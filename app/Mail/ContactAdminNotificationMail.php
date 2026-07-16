<?php

namespace App\Mail;

use App\Mail\Concerns\UsesSupportSender;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactAdminNotificationMail extends Mailable
{
    use SerializesModels;
    use UsesSupportSender;

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
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            // Sets ->mailer('support') when configured; otherwise falls back to the
            // default mailer with a matching From — see UsesSupportSender.
            from:    $this->resolveSupportSender(),
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
