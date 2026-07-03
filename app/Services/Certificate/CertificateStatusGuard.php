<?php

namespace App\Services\Certificate;

use App\Models\Certificate;
use Illuminate\Validation\ValidationException;

/**
 * Enforces valid certificate status transitions.
 *
 * Any attempt to move a certificate to a status that is not reachable
 * from its current status throws a ValidationException with an Arabic message.
 */
class CertificateStatusGuard
{
    /**
     * Allowed transitions: current status → set of permitted next statuses.
     */
    private const TRANSITIONS = [
        'pending'            => ['pending_approval', 'approved', 'rejected', 'revoked'],
        'pending_approval'   => ['approved', 'rejected', 'both_approved'],
        'approved'           => ['issued', 'revoked'],
        'pending_generation' => ['issued', 'generation_failed'],
        'generation_failed'  => ['pending_generation', 'issued'],
        'issued'             => ['revoked'],
        'rejected'           => ['pending', 'pending_approval'],   // re-submission only
        'revoked'            => [],                                // terminal
    ];

    /**
     * Assert that transitioning $certificate to $newStatus is valid.
     *
     * @throws ValidationException  with Arabic message on invalid transition.
     */
    public static function assertCanTransition(Certificate $certificate, string $newStatus): void
    {
        $current = $certificate->status;

        if ($current === $newStatus) {
            return;
        }

        $allowed = self::TRANSITIONS[$current] ?? [];

        if (! in_array($newStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => [
                    "لا يمكن تغيير حالة الشهادة من «{$current}» إلى «{$newStatus}».",
                ],
            ]);
        }
    }

    /**
     * Check without throwing — useful for conditional logic.
     */
    public static function canTransition(Certificate $certificate, string $newStatus): bool
    {
        $current = $certificate->status;
        if ($current === $newStatus) return true;
        $allowed = self::TRANSITIONS[$current] ?? [];
        return in_array($newStatus, $allowed, true);
    }
}
