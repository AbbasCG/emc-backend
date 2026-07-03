<?php

namespace App\Mail;

use App\Models\Certificate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CertificateRevokedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Certificate $certificate,
        public readonly ?string     $reason = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'إشعار إلغاء شهادتك — ' . config('app.name'));
    }

    public function content(): Content
    {
        $cert = $this->certificate;

        return new Content(
            view: 'emails.certificate-revoked',
            with: [
                'studentName'      => $cert->recipient_name ?? $cert->user?->name ?? 'المتدرب',
                'certificateTitle' => $cert->title,
                'certificateCode'  => $cert->certificate_code,
                'reason'           => $this->reason ?? $cert->revoke_reason,
            ],
        );
    }
}
