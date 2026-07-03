<?php

namespace App\Mail;

use App\Models\Certificate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CertificateReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string  $studentName,
        public readonly string  $entityTitle,
        public readonly string  $certificateCode,
        public readonly ?string $issuedAt,
        public readonly string  $downloadUrl,
        public readonly ?string $verificationCode = null,
    ) {}

    public static function fromCertificate(Certificate $certificate): self
    {
        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');

        return new self(
            studentName:      $certificate->recipient_name ?? $certificate->user?->name ?? '',
            entityTitle:      $certificate->course?->title
                              ?? $certificate->workshop?->title
                              ?? $certificate->track?->name
                              ?? $certificate->title
                              ?? 'شهادتك',
            certificateCode:  $certificate->certificate_code ?? '',
            issuedAt:         $certificate->issued_at?->format('Y-m-d'),
            downloadUrl:      $frontend . '/dashboard/certificates',
            verificationCode: $certificate->verification_code,
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "شهادتك جاهزة للتحميل: {$this->entityTitle}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.certificate-ready');
    }
}
