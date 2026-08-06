<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to both the student and the instructor once an oral-assessment
 * booking is confirmed. Same template, `$isInstructor` swaps the greeting
 * and a couple of labels — kept as one Mailable/view rather than two, since
 * the content is otherwise identical (course, date, start/end, timezone,
 * meeting link, cancel/reschedule policy).
 */
class OralAssessmentBookingConfirmedMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly bool $isInstructor,
        public readonly string $recipientName,
        public readonly string $otherPartyLabel,
        public readonly string $otherPartyName,
        public readonly string $courseTitle,
        public readonly string $dateLabel,
        public readonly string $startTimeLabel,
        public readonly string $endTimeLabel,
        public readonly string $timezoneLabel,
        public readonly ?string $meetingLink,
        public readonly string $bookingReference,
        public readonly ?string $icsContent = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isInstructor
                ? 'حجز جديد لمقابلة شفهية — ' . $this->courseTitle
                : 'تم تأكيد موعد المقابلة الشفهية — ' . $this->courseTitle,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.oral-assessment-booking-confirmed',
            with: [
                'isInstructor'    => $this->isInstructor,
                'recipientName'   => $this->recipientName,
                'otherPartyLabel' => $this->otherPartyLabel,
                'otherPartyName'  => $this->otherPartyName,
                'courseTitle'     => $this->courseTitle,
                'dateLabel'       => $this->dateLabel,
                'startTimeLabel'  => $this->startTimeLabel,
                'endTimeLabel'    => $this->endTimeLabel,
                'timezoneLabel'   => $this->timezoneLabel,
                'meetingLink'     => $this->meetingLink,
                'bookingReference' => $this->bookingReference,
                'dashboardUrl'    => rtrim((string) config('app.frontend_url', config('app.url')), '/')
                    . ($this->isInstructor ? '/dashboard/instructor/oral-assessments' : '/dashboard/student/courses'),
            ],
        );
    }

    public function attachments(): array
    {
        if (! $this->icsContent) {
            return [];
        }

        return [
            Attachment::fromData(fn () => $this->icsContent, 'oral-assessment.ics')
                ->withMime('text/calendar'),
        ];
    }
}
