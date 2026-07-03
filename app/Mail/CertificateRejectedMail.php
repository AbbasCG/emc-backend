<?php

namespace App\Mail;

use App\Models\Certificate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CertificateRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string  $studentName,
        public readonly string  $entityTitle,
        public readonly string  $certificateCode,
        public readonly ?string $rejectionReason = null,
    ) {}

    public static function fromCertificate(Certificate $certificate, ?string $reason = null): self
    {
        return new self(
            studentName:     $certificate->recipient_name ?? $certificate->user?->name ?? '',
            entityTitle:     $certificate->course?->title
                             ?? $certificate->workshop?->title
                             ?? $certificate->track?->name
                             ?? $certificate->title
                             ?? 'الشهادة',
            certificateCode: $certificate->certificate_code ?? '',
            rejectionReason: $reason,
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'إشعار بشأن طلب شهادتك — ' . config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.certificate-rejected');
    }
}
