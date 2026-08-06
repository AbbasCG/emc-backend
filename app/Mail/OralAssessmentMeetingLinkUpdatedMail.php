<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to the student when the instructor adds, changes, or removes the meeting link for an already-booked interview. */
class OralAssessmentMeetingLinkUpdatedMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly string $studentName,
        public readonly string $instructorName,
        public readonly string $courseTitle,
        public readonly string $dateLabel,
        public readonly string $startTimeLabel,
        public readonly string $endTimeLabel,
        public readonly ?string $meetingLink,
        public readonly ?string $icsContent = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تحديث رابط اجتماع المقابلة الشفهية — ' . $this->courseTitle);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.oral-assessment-meeting-link-updated',
            with: [
                'studentName'    => $this->studentName,
                'instructorName' => $this->instructorName,
                'courseTitle'    => $this->courseTitle,
                'dateLabel'      => $this->dateLabel,
                'startTimeLabel' => $this->startTimeLabel,
                'endTimeLabel'   => $this->endTimeLabel,
                'meetingLink'    => $this->meetingLink,
                'dashboardUrl'   => rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/dashboard/student/courses',
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
