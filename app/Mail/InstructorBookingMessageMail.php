<?php

namespace App\Mail;

use App\Mail\Concerns\UsesSupportSender;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Free-text message an instructor sends a student about a specific oral-interview booking. */
class InstructorBookingMessageMail extends Mailable
{
    use SerializesModels;
    use UsesSupportSender;

    public function __construct(
        public readonly string $studentName,
        public readonly string $instructorName,
        public readonly ?string $instructorEmail,
        public readonly string $courseTitle,
        public readonly string $body,
        public readonly string $bookingReference,
    ) {}

    public function envelope(): Envelope
    {
        $from = $this->resolveSupportSender();

        return new Envelope(
            from: $from,
            replyTo: $this->instructorEmail ? [new Address($this->instructorEmail, $this->instructorName)] : [],
            subject: "رسالة بخصوص موعد المقابلة الشفهية ({$this->bookingReference})",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.instructor-booking-message');
    }
}
