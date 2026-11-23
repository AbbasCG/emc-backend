<?php

namespace App\Services;

use App\Models\VolunteerPointAward;
use App\Models\VolunteerProfile;

/**
 * The two writes behind "credit a volunteer for finished work": a row in the Impact Points ledger and an increment
 * of the cumulative volunteer hours. Shared by regular tasks (TaskWorkflowService) and tickets
 * (TicketCreditService) so there is ONE implementation of what a credit is.
 *
 * Both methods must run inside the caller's DB transaction: the caller owns atomicity and decides when a credit
 * is allowed (and, for tickets, guards against a second one with a row lock and the ticket_credits unique index).
 */
class VolunteerCreditService
{
    /**
     * Add the points to the ledger, once per source: when a ledger row for (source_type, source_id) already
     * exists nothing is written and null is returned.
     */
    public static function awardPoints(
        int $userId,
        int $points,
        string $category,
        string $reason,
        ?string $note,
        ?int $awardedBy,
        string $sourceType,
        int $sourceId,
    ): ?VolunteerPointAward {
        // منع الازدواجية إذا كان قد تم منحه مسبقاً لهذا المصدر
        $alreadyAwarded = VolunteerPointAward::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();

        if ($alreadyAwarded) {
            return null;
        }

        return VolunteerPointAward::create([
            'user_id'     => $userId,
            'category'    => $category,
            'points'      => $points,
            'reason'      => $reason,
            'note'        => $note,
            'awarded_by'  => $awardedBy,
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
            'awarded_at'  => now(),
        ]);
    }

    /** Add to the cumulative volunteer hours (the profile is created on first use). */
    public static function addHours(int $userId, float $hours): void
    {
        $profile = VolunteerProfile::firstOrCreate(
            ['user_id' => $userId],
            ['hours_logged' => 0]
        );
        $profile->increment('hours_logged', $hours);
    }
}
