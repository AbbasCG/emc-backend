<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactAdminNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string  $visitorName,
        public readonly string  $visitorEmail,
        public readonly ?string $visitorPhone,
        public readonly string  $subject,
        public readonly string  $messageBody,
        public readonly string  $category,
        public readonly string  $submittedAt,
        public readonly ?string $adminUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'رسالة جديدة من نموذج التواصل — ' . $this->visitorName);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-admin-notification',
            with: [
                'visitorName'  => $this->visitorName,
                'visitorEmail' => $this->visitorEmail,
                'visitorPhone' => $this->visitorPhone,
                'subject'      => $this->subject,
                'messageBody'  => $this->messageBody,
                'category'     => $this->category,
                'submittedAt'  => $this->submittedAt,
                'adminUrl'     => $this->adminUrl ?? rtrim(config('app.frontend_url', config('app.url')), '/') . '/dashboard/admin/support',
            ],
        );
    }
}
