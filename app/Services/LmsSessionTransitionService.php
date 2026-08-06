<?php

namespace App\Services;

use App\Models\LmsSession;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Single source of truth for legal LmsSession status transitions. Frontend
 * never owns this matrix — every transition (including from `updateSession`'s
 * `status` field) must pass through here.
 */
class LmsSessionTransitionService
{
    /** @var array<string, array<int, string>> */
    private const MATRIX = [
        LmsSession::STATUS_SCHEDULED => [LmsSession::STATUS_LIVE, LmsSession::STATUS_CANCELLED, LmsSession::STATUS_MISSED],
        LmsSession::STATUS_LIVE      => [LmsSession::STATUS_COMPLETED, LmsSession::STATUS_CANCELLED],
        LmsSession::STATUS_COMPLETED => [LmsSession::STATUS_ARCHIVED],
        LmsSession::STATUS_CANCELLED => [LmsSession::STATUS_ARCHIVED],
        LmsSession::STATUS_MISSED    => [LmsSession::STATUS_ARCHIVED],
        LmsSession::STATUS_ARCHIVED  => [],
    ];

    /** @return array<int, string> */
    public function allowedTransitions(LmsSession $session): array
    {
        return self::MATRIX[$session->status] ?? [];
    }

    public function canTransition(LmsSession $session, string $targetStatus): bool
    {
        if (! in_array($targetStatus, LmsSession::STATUSES, true)) {
            return false;
        }

        return in_array($targetStatus, $this->allowedTransitions($session), true);
    }

    public function transition(LmsSession $session, string $targetStatus, User $actor): LmsSession
    {
        if (! $this->canTransition($session, $targetStatus)) {
            throw ValidationException::withMessages([
                'status' => "لا يمكن نقل الجلسة من الحالة \"{$session->status}\" إلى \"{$targetStatus}\".",
            ]);
        }

        $session->status = $targetStatus;
        $session->save();

        return $session->fresh();
    }
}
