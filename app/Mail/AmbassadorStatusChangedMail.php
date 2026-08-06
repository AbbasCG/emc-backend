<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AmbassadorStatusChangedMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly string  $applicantName,
        public readonly string  $newStatus,
        public readonly ?string $reason,
        public readonly mixed   $interviewAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تحديث حالة طلبك كسفير للتحول الرقمي — منصة EMC');
    }

    public function content(): Content
    {
        $statusLabels = [
            'new'                  => 'جديد',
            'under_review'         => 'قيد المراجعة',
            'interview_scheduled'  => 'تمت جدولة مقابلة',
            'approved'             => 'تمت الموافقة',
            'rejected'             => 'مرفوض',
            'waitlisted'           => 'على قائمة الانتظار',
            'cancelled'            => 'ملغى',
        ];

        return new Content(
            view: 'emails.ambassador-status-changed',
            with: [
                'applicantName' => $this->applicantName,
                'newStatus'     => $this->newStatus,
                'statusLabel'   => $statusLabels[$this->newStatus] ?? $this->newStatus,
                'reason'        => $this->reason,
                'interviewAt'   => $this->interviewAt
                    ? (is_string($this->interviewAt) ? $this->interviewAt : $this->interviewAt->format('Y-m-d H:i'))
                    : null,
            ],
        );
    }
}
