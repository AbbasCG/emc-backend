<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\User;
use Illuminate\Support\Str;

class CertificateService
{
    public function issue(Certificate $certificate, User $approver): Certificate
    {
        $certificate->update([
            'status'      => 'issued',
            'issued_at'   => now(),
            'approved_by' => $approver->id,
        ]);

        ActivityLogger::log('certificate.issued', $certificate, [], request());

        return $certificate->fresh();
    }

    public function approve(Certificate $certificate, User $approver): Certificate
    {
        $certificate->update([
            'status'      => 'approved',
            'approved_by' => $approver->id,
        ]);

        ActivityLogger::log('certificate.approved', $certificate, [], request());

        return $certificate->fresh();
    }

    public function revoke(Certificate $certificate, User $admin): Certificate
    {
        $certificate->update(['status' => 'revoked']);

        ActivityLogger::log('certificate.revoked', $certificate, [], request());

        return $certificate->fresh();
    }

    public function generateVerificationCode(): string
    {
        do {
            $code = strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4));
        } while (Certificate::where('verification_code', $code)->exists());

        return $code;
    }
}
