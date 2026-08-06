<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Mail\InstructorBookingMessageMail;
use App\Models\Instructor;
use App\Models\OralAssessmentBooking;
use App\Models\OralAssessmentSlot;
use App\Services\EmailService;
use App\Services\NotificationService;
use App\Services\OralAssessmentSchedulingService;
use App\Services\OralBookingPresenter;
use App\Support\MeetingProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Post-booking actions on a placement-interview appointment: view full
 * detail, change status (confirm/complete/no-show/cancel), send a message to
 * the student, and reschedule. Deliberately separate from
 * InstructorAvailabilityController, which only manages slot CRUD.
 */
class InstructorOralBookingController extends Controller
{
    public function __construct(private readonly OralAssessmentSchedulingService $scheduling)
    {
    }

    private const TERMINAL_STATUSES = [
        OralAssessmentBooking::STATUS_COMPLETED,
        OralAssessmentBooking::STATUS_CANCELLED_BY_STUDENT,
        OralAssessmentBooking::STATUS_CANCELLED_BY_INSTRUCTOR,
        OralAssessmentBooking::STATUS_NO_SHOW,
    ];

    private const ALLOWED_TRANSITIONS = [
        OralAssessmentBooking::STATUS_CONFIRMED,
        OralAssessmentBooking::STATUS_COMPLETED,
        OralAssessmentBooking::STATUS_NO_SHOW,
        OralAssessmentBooking::STATUS_CANCELLED_BY_INSTRUCTOR,
    ];

    // ─── GET /api/instructor/oral-bookings/{booking} ──────────────────────────

    public function show(Request $request, OralAssessmentBooking $booking): JsonResponse
    {
        $this->authorizeInstructor($request, $booking);

        $booking->load(['user', 'course', 'attempt', 'slot', 'statusHistory.changedBy']);

        return response()->json([
            'success' => true,
            'data'    => array_merge(
                OralBookingPresenter::present($booking, withHistory: true),
                [
                    'starts_at' => $booking->slot?->starts_at->toIso8601String(),
                    'ends_at'   => $booking->slot?->ends_at->toIso8601String(),
                    'meeting_link' => $booking->slot?->meeting_link,
                ],
            ),
        ]);
    }

    // ─── PATCH /api/instructor/oral-bookings/{booking}/status ─────────────────
    //
    // Body: status (confirmed|completed|no_show|cancelled_by_instructor), reason?

    public function updateStatus(Request $request, OralAssessmentBooking $booking): JsonResponse
    {
        $instructor = $this->authorizeInstructor($request, $booking);

        $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', self::ALLOWED_TRANSITIONS)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if (in_array($booking->status, self::TERMINAL_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تعديل حالة موعد وصل بالفعل إلى حالة نهائية.',
            ], 422);
        }

        $newStatus = $request->string('status')->toString();

        DB::transaction(function () use ($booking, $newStatus, $request, $instructor) {
            $booking->changeStatus($newStatus, $instructor->user_id, $request->input('reason'));

            $slotStatus = match ($newStatus) {
                OralAssessmentBooking::STATUS_COMPLETED               => 'completed',
                OralAssessmentBooking::STATUS_NO_SHOW                 => 'completed',
                OralAssessmentBooking::STATUS_CANCELLED_BY_INSTRUCTOR => 'available',
                default                                                => null,
            };

            if ($slotStatus === 'available') {
                // Cancelling releases the slot back to the pool.
                $booking->slot?->update([
                    'booking_status' => 'available',
                    'is_booked'      => false,
                    'booked_by'      => null,
                ]);
            } elseif ($slotStatus !== null) {
                $booking->slot?->update(['booking_status' => $slotStatus]);
            }
        });

        $booking->load(['user', 'course']);

        $statusLabels = [
            OralAssessmentBooking::STATUS_CONFIRMED               => 'مؤكد',
            OralAssessmentBooking::STATUS_COMPLETED                => 'مكتمل',
            OralAssessmentBooking::STATUS_NO_SHOW                  => 'لم يحضر',
            OralAssessmentBooking::STATUS_CANCELLED_BY_INSTRUCTOR  => 'ملغى من قبل المدرب',
        ];

        if ($booking->user_id) {
            NotificationService::oralAssessmentStatusChanged(
                $booking->user_id,
                $booking->course?->title ?? '',
                $statusLabels[$newStatus] ?? $newStatus,
                $request->input('reason'),
            );
        }

        if ($newStatus === OralAssessmentBooking::STATUS_CANCELLED_BY_INSTRUCTOR) {
            $this->scheduling->bookingCancelled($booking);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة الموعد بنجاح.',
            'data'    => OralBookingPresenter::present($booking->fresh(['user', 'course', 'attempt'])),
        ]);
    }

    // ─── PATCH /api/instructor/oral-bookings/{booking}/meeting-link ───────────
    //
    // Body: meeting_link (nullable, string|url), meeting_provider (nullable, one of MeetingProvider)
    // Add / edit / replace: send a non-empty meeting_link. Remove: send meeting_link=null.

    public function updateMeetingLink(Request $request, OralAssessmentBooking $booking): JsonResponse
    {
        $this->authorizeInstructor($request, $booking);

        $request->validate([
            'meeting_link'     => ['nullable', 'string', 'url', 'max:500'],
            'meeting_provider' => ['nullable', 'string', 'in:' . implode(',', MeetingProvider::values())],
        ]);

        $slot = $booking->slot;
        if (! $slot) {
            return response()->json(['success' => false, 'message' => 'لا يوجد موعد مرتبط بهذا الحجز.'], 422);
        }

        $newLink = $request->filled('meeting_link') ? $request->string('meeting_link')->toString() : null;

        $slot->update([
            'meeting_link'     => $newLink,
            'meeting_provider' => $newLink ? ($request->input('meeting_provider') ?? MeetingProvider::CUSTOM_URL->value) : null,
        ]);

        $booking->load(['user', 'course', 'slot']);

        if ($booking->user_id) {
            NotificationService::oralAssessmentMeetingLinkUpdated(
                $booking->user_id,
                $booking->course?->title ?? '',
                $booking->id,
                $newLink,
            );
        }

        // Keeps both calendar events + the student's copy of the ICS in sync,
        // and emails the student the new link — see OralAssessmentSchedulingService.
        $this->scheduling->meetingLinkUpdated($booking);

        return response()->json([
            'success' => true,
            'message' => $newLink ? 'تم تحديث رابط الاجتماع بنجاح.' : 'تمت إزالة رابط الاجتماع.',
            'data'    => [
                'meeting_link'     => $slot->fresh()->meeting_link,
                'meeting_provider' => $slot->fresh()->meeting_provider,
            ],
        ]);
    }

    // ─── POST /api/instructor/oral-bookings/{booking}/message ─────────────────
    //
    // Body: body (string), send_email (bool, default true)

    public function sendMessage(Request $request, OralAssessmentBooking $booking): JsonResponse
    {
        $instructor = $this->authorizeInstructor($request, $booking);

        $request->validate([
            'body'       => ['required', 'string', 'max:2000'],
            'send_email' => ['boolean'],
        ]);

        $booking->load(['user', 'course', 'slot']);

        if (! $booking->user) {
            return response()->json(['success' => false, 'message' => 'لا يوجد طالب مرتبط بهذا الموعد.'], 422);
        }

        $instructorUser = $request->user();
        $body           = $request->string('body')->toString();

        // Only forward the meeting link when the booking is still active — a
        // cancelled/completed booking must never hand the student a "live"
        // link to click through the notification.
        $meetingUrl = $booking->status === 'booked'
            ? $booking->slot?->meeting_link
            : null;

        // In-platform: always delivered, appears in the student's notifications.
        NotificationService::oralAssessmentInstructorMessage(
            $booking->user_id,
            $booking->id,
            $instructorUser->name,
            $body,
            $meetingUrl,
        );

        // Email: optional, queued + logged through the existing Email Logs system.
        if ($request->boolean('send_email', true)) {
            EmailService::dispatch(
                key: 'oral_assessment_instructor_message',
                toEmail: $booking->user->email,
                toName: $booking->user->name,
                userId: $booking->user_id,
                mailable: new InstructorBookingMessageMail(
                    studentName: $booking->user->name,
                    instructorName: $instructorUser->name,
                    instructorEmail: $instructorUser->email,
                    courseTitle: $booking->course?->title ?? '',
                    body: $body,
                    bookingReference: $booking->reference(),
                ),
                subject: "رسالة بخصوص موعد المقابلة الشفهية ({$booking->reference()})",
                context: [
                    'triggered_by_user_id' => $instructorUser->id,
                    'related_course_id'    => $booking->course_id,
                ],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال الرسالة بنجاح.',
        ]);
    }

    // ─── POST /api/instructor/oral-bookings/{booking}/reschedule ──────────────
    //
    // Body: new_slot_id (int), note? (string)

    public function reschedule(Request $request, OralAssessmentBooking $booking): JsonResponse
    {
        $instructor = $this->authorizeInstructor($request, $booking);

        $request->validate([
            'new_slot_id' => ['required', 'integer', 'exists:oral_assessment_slots,id'],
            'note'        => ['nullable', 'string', 'max:1000'],
        ]);

        if (in_array($booking->status, self::TERMINAL_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن نقل موعد وصل بالفعل إلى حالة نهائية.',
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($booking, $request, $instructor) {
                $oldSlot = OralAssessmentSlot::where('id', $booking->slot_id)->lockForUpdate()->first();
                $newSlot = OralAssessmentSlot::where('id', $request->integer('new_slot_id'))->lockForUpdate()->first();

                if (! $newSlot || (int) $newSlot->instructor_id !== $instructor->id) {
                    abort(422, 'الموعد الجديد غير صالح.');
                }
                if (! $newSlot->isAvailable()) {
                    abort(422, 'الموعد الجديد لم يعد متاحًا. يرجى اختيار موعد آخر.');
                }
                if ($newSlot->id === $oldSlot?->id) {
                    abort(422, 'يرجى اختيار موعد مختلف عن الموعد الحالي.');
                }

                // Reserve the new slot first (unique row lock prevents a
                // concurrent request from double-booking it), then release the old one.
                $newSlot->update([
                    'is_booked'      => true,
                    'booked_by'      => $booking->user_id,
                    'booking_status' => 'booked',
                ]);

                $oldSlot?->update([
                    'is_booked'      => false,
                    'booked_by'      => null,
                    'booking_status' => 'available',
                ]);

                $booking->update(['slot_id' => $newSlot->id]);
                $booking->changeStatus(
                    OralAssessmentBooking::STATUS_RESCHEDULED,
                    $instructor->user_id,
                    $request->input('note'),
                );

                return $newSlot;
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'تعذّر نقل الموعد.',
            ], $e->getStatusCode());
        }

        $booking->load(['user', 'course']);

        if ($booking->user_id) {
            $bookingTz = config('app.booking_timezone', 'Europe/Amsterdam');
            $newStart  = $result->starts_at->copy()->setTimezone($bookingTz);
            $newEnd    = $result->ends_at->copy()->setTimezone($bookingTz);
            NotificationService::oralAssessmentRescheduled(
                $booking->user_id,
                $booking->course?->title ?? '',
                $newStart->format('Y-m-d H:i') . ' - ' . $newEnd->format('H:i'),
            );
        }

        // Both calendar events must reflect the new time/link, not the old one.
        $this->scheduling->bookingRescheduled($booking);

        return response()->json([
            'success' => true,
            'message' => 'تم نقل الموعد بنجاح.',
            'data'    => OralBookingPresenter::present($booking->fresh(['user', 'course', 'attempt'])),
        ]);
    }

    // ─── Private ────────────────────────────────────────────────────────────

    private function authorizeInstructor(Request $request, OralAssessmentBooking $booking): Instructor
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->firstOrFail();

        if ((int) $booking->instructor_id !== $instructor->id) {
            abort(403, 'ليس لديك صلاحية الوصول إلى هذا الموعد.');
        }

        return $instructor;
    }
}
