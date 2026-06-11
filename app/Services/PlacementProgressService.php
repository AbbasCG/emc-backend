<?php

namespace App\Services;

use App\Models\OralAssessmentBooking;
use App\Models\PlacementTestAttempt;

/**
 * Single source of truth for placement test progress.
 *
 * All controllers must call this service — never duplicate deriveStatus() or
 * build placement shapes inline.
 *
 * Canonical status lifecycle (in order of completion):
 *   not_started       — no attempt exists
 *   in_progress       — attempt started but not submitted
 *   written_completed — written test done, oral not yet booked
 *   oral_booked       — oral session reserved, interview pending
 *   oral_completed    — interview done, final_level not yet recorded
 *   completed         — final_level recorded; student may access course
 */
class PlacementProgressService
{
    const STATUS_NOT_STARTED       = 'not_started';
    const STATUS_IN_PROGRESS       = 'in_progress';
    const STATUS_WRITTEN_COMPLETED = 'written_completed';
    const STATUS_ORAL_BOOKED       = 'oral_booked';
    const STATUS_ORAL_COMPLETED    = 'oral_completed';
    const STATUS_COMPLETED         = 'completed';

    /**
     * Derive the canonical placement status from an attempt and booking.
     */
    public function deriveStatus(
        ?PlacementTestAttempt $attempt,
        ?OralAssessmentBooking $booking
    ): string {
        if (! $attempt) {
            return self::STATUS_NOT_STARTED;
        }
        if ($attempt->status !== 'completed') {
            return self::STATUS_IN_PROGRESS;
        }
        if (! $booking) {
            return self::STATUS_WRITTEN_COMPLETED;
        }
        if ($booking->status === 'booked') {
            return self::STATUS_ORAL_BOOKED;
        }
        if ($booking->status === 'completed' && ! $booking->final_level) {
            return self::STATUS_ORAL_COMPLETED;
        }
        return self::STATUS_COMPLETED;
    }

    /**
     * Build the unified placement_progress object for API responses.
     *
     * Returns null when $requiresPlacement is false so callers can pass the flag
     * directly without an if-statement.
     *
     * Relations needed on $booking for full output:
     *   - slot (OralAssessmentSlot)
     *   - instructor (Instructor, optional — name null if not loaded)
     */
    public function progress(
        ?PlacementTestAttempt $attempt,
        ?OralAssessmentBooking $booking,
        bool $requiresPlacement = true
    ): ?array {
        if (! $requiresPlacement) {
            return null;
        }

        $status = $this->deriveStatus($attempt, $booking);

        return [
            'status'            => $status,
            'can_access_course' => $status === self::STATUS_COMPLETED,
            'written_test'      => $attempt ? $this->writtenTestShape($attempt) : null,
            'oral_assessment'   => $booking  ? $this->oralAssessmentShape($booking)  : null,
        ];
    }

    /**
     * Human-readable Arabic label for a canonical status value.
     */
    public function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_NOT_STARTED       => 'لم يبدأ الاختبار بعد',
            self::STATUS_IN_PROGRESS       => 'الاختبار جارٍ',
            self::STATUS_WRITTEN_COMPLETED => 'بانتظار المقابلة الشفوية',
            self::STATUS_ORAL_BOOKED       => 'المقابلة الشفوية محجوزة',
            self::STATUS_ORAL_COMPLETED    => 'اكتملت المقابلة — بانتظار تسجيل المستوى',
            self::STATUS_COMPLETED         => 'اكتمل التقييم',
            default                        => $status,
        };
    }

    // ── Shapes (also used directly by controllers that need sub-objects) ──────

    public function writtenTestShape(PlacementTestAttempt $attempt): array
    {
        $done         = $attempt->status === 'completed';
        $levelDetails = ($done && $attempt->estimated_level)
            ? PlacementScoringService::levelFromCode($attempt->estimated_level)
            : null;

        return [
            'id'                   => $attempt->id,
            'status'               => $attempt->status,
            'score'                => $done ? $attempt->score      : null,
            'total_questions'      => $attempt->total_questions,
            'percentage'           => $done ? $attempt->percentage : null,
            'estimated_level'      => $done ? $attempt->estimated_level : null,
            'estimated_level_code' => $levelDetails ? $levelDetails['code'] : null,
            'estimated_level_en'   => $levelDetails ? $levelDetails['en']   : null,
            'estimated_level_ar'   => $levelDetails ? $levelDetails['ar']   : null,
            'submitted_at'         => $attempt->submitted_at?->toIso8601String(),
        ];
    }

    public function oralAssessmentShape(OralAssessmentBooking $booking): array
    {
        $slot       = $booking->relationLoaded('slot')       ? $booking->slot       : null;
        $instructor = $booking->relationLoaded('instructor') ? $booking->instructor : null;

        return [
            'id'               => $booking->id,
            'status'           => $booking->status,
            'starts_at'        => $slot?->starts_at->toIso8601String(),
            'ends_at'          => $slot?->ends_at->toIso8601String(),
            'meeting_link'     => $slot?->meeting_link,
            'instructor_name'  => $instructor?->user?->name ?? $instructor?->name,
            'final_level'      => $booking->final_level,
            'oral_score'       => $booking->oral_score,
            'instructor_notes' => $booking->instructor_notes,
        ];
    }
}
