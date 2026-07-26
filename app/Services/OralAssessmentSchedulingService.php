<?php

namespace App\Services;

use App\Mail\OralAssessmentBookingConfirmedMail;
use App\Mail\OralAssessmentMeetingLinkUpdatedMail;
use App\Models\CalendarEvent;
use App\Models\OralAssessmentBooking;
use Illuminate\Support\Carbon;

/**
 * Owns the full "appointment experience" for an oral-assessment booking:
 * a CalendarEvent per attendee (reusing the existing polymorphic
 * eventable relation — no separate scheduling table), ICS generation via
 * the existing CalendarService, Google Calendar sync via the existing
 * (stubbed) CalendarService::syncToGoogle(), and confirmation/update
 * emails via the existing EmailService + queue.
 *
 * Deliberately NOT responsible for in-app notifications — those stay in
 * NotificationService, called by the controllers directly, same as every
 * other scenario in the platform.
 */
class OralAssessmentSchedulingService
{
    public function __construct(private readonly CalendarService $calendarService)
    {
    }

    /**
     * Create the student + instructor calendar events and send both
     * confirmation emails. Called once, right after a booking is created.
     */
    public function bookingConfirmed(OralAssessmentBooking $booking): void
    {
        $booking->loadMissing(['slot', 'instructor.user', 'user', 'course']);
        $slot = $booking->slot;
        if (! $slot) {
            return;
        }

        $tz = config('app.booking_timezone', 'Europe/Amsterdam');
        $startsLocal = $slot->starts_at->copy()->setTimezone($tz);
        $endsLocal   = $slot->ends_at?->copy()->setTimezone($tz);

        $studentName    = $booking->user?->name ?? '';
        $instructorName = $booking->instructor?->user?->name ?? $booking->instructor?->name ?? '';
        $courseTitle    = $booking->course?->title ?? '';
        $title          = "مقابلة تحديد المستوى — {$courseTitle}";
        $description    = "الدورة: {$courseTitle}\nالطالب: {$studentName}\nالمدرب: {$instructorName}\nالحالة: {$booking->status}"
            . ($slot->meeting_link ? "\nرابط الاجتماع: {$slot->meeting_link}" : '');

        $studentEvent = $instructorEvent = null;

        if ($booking->user_id) {
            $studentEvent = $this->upsertCalendarEvent($booking, $booking->user_id, $title, $description, $slot->starts_at, $slot->ends_at, $slot->meeting_link);
        }
        if ($booking->instructor?->user_id) {
            $instructorEvent = $this->upsertCalendarEvent($booking, $booking->instructor->user_id, $title, $description, $slot->starts_at, $slot->ends_at, $slot->meeting_link);
        }

        // Google Calendar sync — reuses the existing (currently stubbed)
        // hook; when it's eventually wired to the real API it activates
        // here automatically, no call-site change needed. Falls back to
        // ICS-only (attached to the emails below) until then.
        foreach (array_filter([$studentEvent, $instructorEvent]) as $event) {
            $this->calendarService->syncToGoogle($event);
        }

        $dateLabel  = $startsLocal->translatedFormat('l d F Y');
        $startLabel = $startsLocal->format('H:i');
        $endLabel   = $endsLocal?->format('H:i') ?? '';

        if ($booking->user?->email) {
            EmailService::dispatch(
                'oral_assessment_booking_confirmed',
                $booking->user->email,
                $studentName,
                $booking->user_id,
                new OralAssessmentBookingConfirmedMail(
                    isInstructor:    false,
                    recipientName:   $studentName,
                    otherPartyLabel: 'المدرب',
                    otherPartyName:  $instructorName,
                    courseTitle:     $courseTitle,
                    dateLabel:       $dateLabel,
                    startTimeLabel:  $startLabel,
                    endTimeLabel:    $endLabel,
                    timezoneLabel:   $tz,
                    meetingLink:     $slot->meeting_link,
                    bookingReference: (string) $booking->id,
                    icsContent:      $studentEvent ? $this->calendarService->toIcs($studentEvent) : null,
                ),
                'تم تأكيد موعد المقابلة الشفهية — ' . $courseTitle,
                [],
                ['related_course_id' => $booking->course_id],
            );
        }

        $instructorEmail = $booking->instructor?->user?->email;
        if ($instructorEmail) {
            EmailService::dispatch(
                'oral_assessment_booking_confirmed',
                $instructorEmail,
                $instructorName,
                $booking->instructor?->user_id,
                new OralAssessmentBookingConfirmedMail(
                    isInstructor:    true,
                    recipientName:   $instructorName,
                    otherPartyLabel: 'الطالب',
                    otherPartyName:  $studentName,
                    courseTitle:     $courseTitle,
                    dateLabel:       $dateLabel,
                    startTimeLabel:  $startLabel,
                    endTimeLabel:    $endLabel,
                    timezoneLabel:   $tz,
                    meetingLink:     $slot->meeting_link,
                    bookingReference: (string) $booking->id,
                    icsContent:      $instructorEvent ? $this->calendarService->toIcs($instructorEvent) : null,
                ),
                'حجز جديد لمقابلة شفهية — ' . $courseTitle,
                [],
                ['related_course_id' => $booking->course_id],
            );
        }
    }

    /**
     * Keep both calendar events (and the student's email) in sync after the
     * instructor adds, changes, or removes the meeting link. The instructor
     * already sees the change immediately in their own UI, so only the
     * student's email is sent here — the instructor's own calendar event is
     * still refreshed so it does not go stale.
     */
    public function meetingLinkUpdated(OralAssessmentBooking $booking): void
    {
        $booking->loadMissing(['slot', 'instructor.user', 'user', 'course']);
        $slot = $booking->slot;
        if (! $slot) {
            return;
        }

        $events = CalendarEvent::where('eventable_type', OralAssessmentBooking::class)
            ->where('eventable_id', $booking->id)
            ->get();

        foreach ($events as $event) {
            $event->update([
                'meeting_url' => $slot->meeting_link,
                'location'    => $slot->meeting_link,
                'sync_status' => 'pending',
            ]);
        }

        $tz = config('app.booking_timezone', 'Europe/Amsterdam');
        $startsLocal = $slot->starts_at->copy()->setTimezone($tz);
        $endsLocal   = $slot->ends_at?->copy()->setTimezone($tz);

        $studentEvent = $events->firstWhere('user_id', $booking->user_id);

        if ($booking->user?->email) {
            EmailService::dispatch(
                'oral_assessment_meeting_link_updated',
                $booking->user->email,
                $booking->user->name ?? '',
                $booking->user_id,
                new OralAssessmentMeetingLinkUpdatedMail(
                    studentName:    $booking->user->name ?? '',
                    instructorName: $booking->instructor?->user?->name ?? $booking->instructor?->name ?? '',
                    courseTitle:    $booking->course?->title ?? '',
                    dateLabel:      $startsLocal->translatedFormat('l d F Y'),
                    startTimeLabel: $startsLocal->format('H:i'),
                    endTimeLabel:   $endsLocal?->format('H:i') ?? '',
                    meetingLink:    $slot->meeting_link,
                    icsContent:     $studentEvent ? $this->calendarService->toIcs($studentEvent) : null,
                ),
                'تحديث رابط اجتماع المقابلة الشفهية — ' . ($booking->course?->title ?? ''),
                [],
                ['related_course_id' => $booking->course_id],
            );
        }
    }

    /**
     * Updates both attendees' calendar events (new start/end, current
     * meeting link) after a reschedule. The in-app "rescheduled"
     * notification (NotificationService::oralAssessmentRescheduled) already
     * covers communication — this only keeps the calendar/ICS side honest,
     * so re-downloading the event does not show the old time.
     */
    public function bookingRescheduled(OralAssessmentBooking $booking): void
    {
        $booking->loadMissing(['slot']);
        $slot = $booking->slot;
        if (! $slot) {
            return;
        }

        CalendarEvent::where('eventable_type', OralAssessmentBooking::class)
            ->where('eventable_id', $booking->id)
            ->update([
                'start_at'    => $slot->starts_at,
                'end_at'      => $slot->ends_at,
                'meeting_url' => $slot->meeting_link,
                'location'    => $slot->meeting_link,
                'sync_status' => 'pending',
            ]);
    }

    /**
     * Removes both attendees' calendar events when a booking is cancelled
     * (or marked no-show) — an already-sent .ics stays valid on the
     * recipient's own calendar app (no live "un-send" is possible), but the
     * platform's own calendar/export no longer lists it, and the meeting
     * link becomes unreachable via any EMC surface. The in-app/notification
     * side (NotificationService::oralAssessmentStatusChanged) is handled by
     * the caller, same as every other status transition.
     */
    public function bookingCancelled(OralAssessmentBooking $booking): void
    {
        CalendarEvent::where('eventable_type', OralAssessmentBooking::class)
            ->where('eventable_id', $booking->id)
            ->delete();
    }

    private function upsertCalendarEvent(
        OralAssessmentBooking $booking,
        int $userId,
        string $title,
        string $description,
        Carbon $startsAt,
        ?Carbon $endsAt,
        ?string $meetingLink,
    ): CalendarEvent {
        $existing = CalendarEvent::where('eventable_type', OralAssessmentBooking::class)
            ->where('eventable_id', $booking->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            $existing->update([
                'title'       => $title,
                'description' => $description,
                'start_at'    => $startsAt,
                'end_at'      => $endsAt,
                'location'    => $meetingLink,
                'meeting_url' => $meetingLink,
            ]);

            return $existing->fresh();
        }

        return $this->calendarService->createFromModel(
            $booking,
            $title,
            $startsAt,
            $endsAt,
            $userId,
            ['description' => $description, 'location' => $meetingLink, 'meeting_url' => $meetingLink],
        );
    }
}
