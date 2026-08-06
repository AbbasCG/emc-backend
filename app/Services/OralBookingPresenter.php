<?php

namespace App\Services;

use App\Models\OralAssessmentBooking;

/**
 * Shapes an OralAssessmentBooking into the nested payload the instructor UI
 * needs (student identity, course, placement score/level, reference, status)
 * — used by both the availability-slot list and the booking-detail endpoint
 * so the two never drift out of sync.
 *
 * Callers must eager-load: user, course, attempt, statusHistory.changedBy
 * (statusHistory only needed when $withHistory = true) to avoid N+1 queries.
 */
class OralBookingPresenter
{
    public static function present(OralAssessmentBooking $booking, bool $withHistory = false): array
    {
        $bookingTz = config('app.booking_timezone', 'Europe/Amsterdam');
        $user      = $booking->user;
        $attempt   = $booking->attempt;

        $percentage = $attempt?->percentage;
        $percentage = $percentage !== null ? round((float) $percentage) : null;

        return [
            'id'         => $booking->id,
            'reference'  => $booking->reference(),
            'status'     => $booking->status,
            'is_active'  => in_array($booking->status, OralAssessmentBooking::ACTIVE_STATUSES, true),
            'booked_at'  => $booking->created_at?->copy()->setTimezone($bookingTz)->toIso8601String(),
            'student'    => $user ? [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'phone'      => $user->phone,
                'avatar_url' => $user->avatar_url,
                'initials'   => self::initials($user->name),
            ] : null,
            'course' => $booking->course ? [
                'id'    => $booking->course->id,
                'title' => $booking->course->title,
            ] : null,
            'placement' => $attempt ? [
                'score'           => $attempt->score,
                'total'           => $attempt->total_questions,
                'percentage'      => $percentage,
                'estimated_level' => $attempt->estimated_level,
            ] : null,
            'oral_score'       => $booking->oral_score,
            'final_level'      => $booking->final_level,
            'instructor_notes' => $booking->instructor_notes,
            'status_history'   => $withHistory
                ? $booking->statusHistory->map(fn ($h) => [
                    'from_status' => $h->from_status,
                    'to_status'   => $h->to_status,
                    'reason'      => $h->reason,
                    'changed_by'  => $h->changedBy?->name,
                    'changed_at'  => $h->changed_at?->copy()->setTimezone($bookingTz)->toIso8601String(),
                ])->values()
                : null,
        ];
    }

    private static function initials(?string $name): string
    {
        if (! $name) return '?';
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_filter($parts);
        if (count($parts) === 0) return '?';
        if (count($parts) === 1) return mb_strtoupper(mb_substr($parts[0], 0, 1));
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
    }
}
