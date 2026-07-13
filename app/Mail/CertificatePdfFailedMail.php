<?php

namespace App\Mail;

use App\Models\Certificate;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CertificatePdfFailedMail extends Mailable
{
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly string  $adminName,
        public readonly string  $certificateTitle,
        public readonly string  $certificateCode,
        public readonly string  $studentName,
        public readonly string  $errorMessage,
        public readonly int     $attempts,
        public readonly string  $adminUrl,
    ) {}

    public static function fromCertificate(Certificate $certificate, string $error, int $attempts, string $adminName): self
    {
        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');

        return new self(
            adminName:        $adminName,
            certificateTitle: $certificate->title ?? $certificate->course?->title ?? 'شهادة',
            certificateCode:  $certificate->certificate_code ?? '',
            studentName:      $certificate->recipient_name ?? $certificate->user?->name ?? '',
            errorMessage:     mb_substr($error, 0, 300),
            attempts:         $attempts,
            adminUrl:         $frontend . '/dashboard/admin/certificates/' . $certificate->id,
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تنبيه: فشل إنشاء شهادة — ' . config('app.name'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.certificate-pdf-failed',
            with: [
                'adminName'        => $this->adminName,
                'certificateTitle' => $this->certificateTitle,
                'certificateCode'  => $this->certificateCode,
                'studentName'      => $this->studentName,
                'errorMessage'     => $this->errorMessage,
                'attempts'         => $this->attempts,
                'adminUrl'         => $this->adminUrl,
            ],
        );
    }
}
