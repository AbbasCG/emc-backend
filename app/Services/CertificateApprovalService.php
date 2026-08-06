<?php

namespace App\Services;

use App\Mail\CertificateRejectedMail;
use App\Models\Certificate;
use App\Models\CertificateApproval;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CertificateApprovalService
{
    public function __construct(
        private readonly CertificatePublishService $publishService,
    ) {}

    /**
     * Record an approval decision for a certificate.
     *
     * @param  string  $type  'administrative' | 'programs'
     * @param  string  $status 'approved' | 'rejected'
     */
    public function decide(
        Certificate $certificate,
        string      $type,
        string      $status,
        User        $approver,
        ?string     $notes = null,
        ?string     $signaturePath = null,
        ?string     $stampPath = null,
    ): CertificateApproval {
        return DB::transaction(function () use ($certificate, $type, $status, $approver, $notes, $signaturePath, $stampPath) {
            $approval = CertificateApproval::firstOrCreate(
                ['certificate_id' => $certificate->id, 'type' => $type],
                ['status' => CertificateApproval::STATUS_PENDING],
            );

            $updateData = [
                'status'        => $status,
                'approved_by'   => $approver->id,
                'approver_role' => $approver->roles()->pluck('name')->first() ?? 'admin',
                'notes'         => $notes,
                'actioned_at'   => now(),
            ];
            if ($signaturePath) $updateData['signature_path'] = $signaturePath;
            if ($stampPath)     $updateData['stamp_path']     = $stampPath;

            $approval->update($updateData);

            Log::info('[Certificate] Approval decision recorded', [
                'certificate_id' => $certificate->id,
                'type'           => $type,
                'status'         => $status,
                'approver_id'    => $approver->id,
                'approver_name'  => $approver->name,
            ]);

            $this->syncCertificateStatus($certificate->fresh(['approvals']));

            return $approval->fresh();
        });
    }

    /**
     * Check both approvals and update the certificate status accordingly.
     */
    public function syncCertificateStatus(Certificate $certificate): void
    {
        $approvals = $certificate->approvals;

        $adminApproval   = $approvals->firstWhere('type', 'administrative');
        $programApproval = $approvals->firstWhere('type', 'programs');

        // Any rejection → status MUST be rejected, never pending
        if (
            ($adminApproval?->status === CertificateApproval::STATUS_REJECTED) ||
            ($programApproval?->status === CertificateApproval::STATUS_REJECTED)
        ) {
            $certificate->update([
                'status'          => Certificate::STATUS_REJECTED,
                'approval_status' => Certificate::APPROVAL_REJECTED,
            ]);

            $rejectionNotes = $approvals
                ->whereIn('status', [CertificateApproval::STATUS_REJECTED])
                ->pluck('notes')
                ->filter()
                ->implode(' | ');

            if ($certificate->user?->email) {
                EmailService::dispatch(
                    'certificate_rejected',
                    $certificate->user->email,
                    $certificate->user->name,
                    $certificate->user_id,
                    CertificateRejectedMail::fromCertificate($certificate->fresh(), $rejectionNotes ?: null),
                    'إشعار بشأن طلب شهادتك — ' . config('app.name'),
                );
            }

            Log::info('[Certificate] Approval: certificate rejected', [
                'certificate_id'  => $certificate->id,
                'admin_status'    => $adminApproval?->status,
                'programs_status' => $programApproval?->status,
            ]);
            return;
        }

        // Both approved → publish (generates real PDF + marks issued)
        if (
            $adminApproval?->status === CertificateApproval::STATUS_APPROVED &&
            $programApproval?->status === CertificateApproval::STATUS_APPROVED
        ) {
            $certificate->update(['approval_status' => Certificate::APPROVAL_BOTH_DONE]);

            Log::info('[Certificate] Approval: both approved — publishing', [
                'certificate_id' => $certificate->id,
            ]);

            $this->publishService->publish($certificate);
            return;
        }

        // Partially approved
        $certificate->update(['approval_status' => Certificate::APPROVAL_PENDING]);

        Log::info('[Certificate] Approval: partial approval', [
            'certificate_id'  => $certificate->id,
            'admin_status'    => $adminApproval?->status ?? 'pending',
            'programs_status' => $programApproval?->status ?? 'pending',
        ]);
    }

    /**
     * Get approval status summary for a certificate.
     */
    public function getStatus(Certificate $certificate): array
    {
        $approvals = $certificate->approvals;

        return [
            'administrative' => $approvals->firstWhere('type', CertificateApproval::TYPE_ADMINISTRATIVE)?->status ?? CertificateApproval::STATUS_PENDING,
            'programs'       => $approvals->firstWhere('type', CertificateApproval::TYPE_PROGRAMS)?->status       ?? CertificateApproval::STATUS_PENDING,
            'both_approved'  => $approvals->every(fn ($a) => $a->status === CertificateApproval::STATUS_APPROVED),
        ];
    }
}
