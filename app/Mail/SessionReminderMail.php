<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SessionReminderMail extends Mailable
{
    use SerializesModels;


    public function __construct(
        public readonly string  $studentName,
        public readonly string  $sessionTitle,
        public readonly string  $courseTitle,
        public readonly string  $startsAt,
        public readonly ?string $meetingLink = null,
        public readonly int     $hoursAhead = 24,
    ) {}

    public function envelope(): Envelope
    {
        $label = $this->hoursAhead >= 20 ? 'غداً' : 'بعد ساعة';
        return new Envelope(subject: "تذكير: جلستك \"{$this->sessionTitle}\" {$label}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.session-reminder');
    }
}
