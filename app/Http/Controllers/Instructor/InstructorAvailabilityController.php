<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\OralAssessmentSlot;
use App\Services\OralBookingPresenter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstructorAvailabilityController extends Controller
{
    // ─── GET /api/instructor/availability ────────────────────────────────────
    //
    // Query params: course_id, upcoming (bool, default true)

    public function index(Request $request): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->firstOrFail();

        // Eager-load everything shape()/OralBookingPresenter need for a booked
        // slot's nested `booking` object — avoids N+1 queries across the list.
        $slots = OralAssessmentSlot::with(['course', 'booking.user', 'booking.course', 'booking.attempt'])
            ->where('instructor_id', $instructor->id)
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', (int) $request->course_id))
            ->when(
                $request->boolean('upcoming', true),
                fn ($q) => $q->where('starts_at', '>', now())
            )
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $slots->map(fn ($slot) => $this->shape($slot)),
        ]);
    }

    // ─── POST /api/instructor/availability ───────────────────────────────────
    //
    // Unified slot-generation endpoint.
    //
    // Payload:
    //   course_id            — required unless apply_to_all_courses=true
    //   apply_to_all_courses — bool (default false)
    //   date_from, date_to   — Y-m-d range (inclusive)
    //   weekdays             — ["saturday","sunday",...] matching days in range
    //   start_time, end_time — H:i window boundaries
    //   slot_duration        — 15|30|45|60 minutes per slot
    //   meeting_link?, notes?
    //
    // Silently skips past slots and exact duplicates (instructor+course+starts_at+ends_at).

    public function store(Request $request): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->firstOrFail();

        $applyToAll = $request->boolean('apply_to_all_courses', false);

        $request->validate([
            'apply_to_all_courses' => ['boolean'],
            'course_id'            => $applyToAll
                ? ['nullable']
                : ['required', 'integer', 'exists:courses,id'],
            'date_from'            => ['required', 'date', 'after_or_equal:today'],
            'date_to'              => ['required', 'date', 'after_or_equal:date_from'],
            'weekdays'             => ['required', 'array', 'min:1'],
            'weekdays.*'           => ['required', 'string', 'in:sunday,monday,tuesday,wednesday,thursday,friday,saturday'],
            'start_time'           => ['required', 'date_format:H:i'],
            'end_time'             => ['required', 'date_format:H:i'],
            'slot_duration'        => ['required', 'integer', 'in:15,30,45,60'],
            'meeting_link'         => ['nullable', 'string', 'max:500'],
            'notes'                => ['nullable', 'string', 'max:1000'],
        ]);

        if ($applyToAll) {
            $courseIds = Course::where('instructor_id', $instructor->id)->pluck('id')->all();
            if (empty($courseIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا توجد دورات مخصصة لك.',
                ], 422);
            }
        } else {
            $courseIds = [(int) $request->course_id];
        }

        // Preload courses so shape() can return course_title without extra queries
        $courses = Course::whereIn('id', $courseIds)->get()->keyBy('id');

        // Instructors pick date/time in their local (Amsterdam) wall clock — not
        // the app's storage timezone (UTC). Parsing with config('app.timezone')
        // here previously misread e.g. "14:30" as 14:30 UTC instead of 14:30
        // Amsterdam, which is the root cause of instructor/student booking-time
        // mismatches (see booking_timezone doc in config/app.php).
        $bookingTz = config('app.booking_timezone', 'Europe/Amsterdam');
        $dayMap  = ['sunday'=>0,'monday'=>1,'tuesday'=>2,'wednesday'=>3,'thursday'=>4,'friday'=>5,'saturday'=>6];
        $dayNums = array_map(fn ($d) => $dayMap[$d], $request->weekdays);
        $dur     = (int) $request->slot_duration;

        $from = Carbon::createFromFormat('Y-m-d', $request->date_from, $bookingTz)->startOfDay();
        $to   = Carbon::createFromFormat('Y-m-d', $request->date_to,   $bookingTz)->endOfDay();
        $now  = now();

        $created = [];
        $skipped = 0;

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            if (! in_array($date->dayOfWeek, $dayNums, true)) {
                continue;
            }

            $dateStr     = $date->format('Y-m-d');
            // Parse as Amsterdam local time, then normalize to UTC for storage —
            // Eloquent's 'datetime' cast round-trips through the app timezone
            // (UTC) on read, so anything stored must already be UTC-equivalent.
            $windowStart = Carbon::createFromFormat('Y-m-d H:i', "{$dateStr} {$request->start_time}", $bookingTz)->utc();
            $windowEnd   = Carbon::createFromFormat('Y-m-d H:i', "{$dateStr} {$request->end_time}",   $bookingTz)->utc();

            $slotStart = $windowStart->copy();

            while ($slotStart->copy()->addMinutes($dur)->lte($windowEnd)) {
                $slotEnd = $slotStart->copy()->addMinutes($dur);

                if ($slotStart->lte($now)) {
                    $skipped++;
                    $slotStart->addMinutes($dur);
                    continue;
                }

                foreach ($courseIds as $courseId) {
                    $exists = OralAssessmentSlot::where('instructor_id', $instructor->id)
                        ->where('course_id', $courseId)
                        ->where('starts_at', $slotStart)
                        ->where('ends_at',   $slotEnd)
                        ->exists();

                    if ($exists) {
                        $skipped++;
                        continue;
                    }

                    $slot = OralAssessmentSlot::create([
                        'instructor_id'  => $instructor->id,
                        'course_id'      => $courseId,
                        'starts_at'      => $slotStart,
                        'ends_at'        => $slotEnd,
                        'booking_status' => 'available',
                        'is_booked'      => false,
                        'meeting_link'   => $request->meeting_link,
                        'notes'          => $request->notes,
                    ]);

                    $slot->setRelation('course', $courses->get($courseId));

                    $created[] = $this->shape($slot);
                }

                $slotStart->addMinutes($dur);
            }
        }

        return response()->json([
            'success'        => true,
            'message'        => 'تم إنشاء ' . count($created) . ' موعد بنجاح.',
            'data'           => [
                'created_count' => count($created),
                'skipped_count' => $skipped,
                'slots'         => $created,
            ],
        ], 201);
    }

    // ─── POST /api/instructor/availability/bulk ──────────────────────────────
    // Legacy alias — delegates to store().

    public function bulk(Request $request): JsonResponse
    {
        return $this->store($request);
    }

    // ─── DELETE /api/instructor/availability/{slot} ───────────────────────────

    public function destroy(Request $request, OralAssessmentSlot $slot): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->firstOrFail();

        if ((int) $slot->instructor_id !== $instructor->id) {
            abort(403, 'ليس لديك صلاحية حذف هذا الموعد.');
        }

        if ($slot->is_booked) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف موعد محجوز بالفعل. ألغِ الحجز أولاً.',
            ], 422);
        }

        $slot->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الموعد بنجاح.',
        ]);
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    private function shape(OralAssessmentSlot $slot): array
    {
        $booking   = $slot->relationLoaded('booking') ? $slot->booking : null;
        $course    = $slot->relationLoaded('course')  ? $slot->course  : null;
        $startsAt  = $slot->starts_at;
        $endsAt    = $slot->ends_at;
        $bookingTz = config('app.booking_timezone', 'Europe/Amsterdam');
        $startsLocal = $startsAt->copy()->setTimezone($bookingTz);
        $endsLocal   = $endsAt->copy()->setTimezone($bookingTz);

        return [
            'id'             => $slot->id,
            'course_id'      => $slot->course_id,
            'course_title'   => $course?->title,
            'starts_at'      => $startsAt->toIso8601String(),
            'ends_at'        => $endsAt->toIso8601String(),
            'timezone'       => $bookingTz,
            'formatted_date' => $startsLocal->format('d/m/Y'),
            'formatted_time' => $startsLocal->format('H:i') . ' - ' . $endsLocal->format('H:i'),
            'is_available'   => $slot->isAvailable(),
            'is_booked'      => $slot->is_booked,
            'booking_status' => $slot->booking_status,
            'meeting_link'   => $slot->meeting_link,
            'notes'          => $slot->notes ?? null,
            // Only ever populated when the slot is actually booked — available
            // slots must never expose student/placement data (Phase 9).
            'booking'        => $booking ? OralBookingPresenter::present($booking) : null,
            // Kept for any existing frontend call sites during rollout.
            'student_name'   => $booking?->user?->name,
        ];
    }
}
