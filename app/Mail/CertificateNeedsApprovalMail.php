<?php

namespace App\Mail;

use App\Models\Certificate;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CertificateNeedsApprovalMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Certificate $certificate,
        public readonly User        $approver,
        public readonly string      $approvalType = 'اعتماد الشهادة',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'طلب اعتماد شهادة — ' . config('app.name'));
    }

    public function content(): Content
    {
        $cert     = $this->certificate;
        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');

        return new Content(
            view: 'emails.certificate-needs-approval',
            with: [
                'approverName'     => $this->approver->name,
                'studentName'      => $cert->recipient_name ?? $cert->user?->name ?? 'المتدرب',
                'certificateTitle' => $cert->title,
                'certificateCode'  => $cert->certificate_code,
                'approvalType'     => $this->approvalType,
                'approvalUrl'      => $frontend . '/dashboard/admin/certificates/' . $cert->id,
            ],
        );
    }
}
