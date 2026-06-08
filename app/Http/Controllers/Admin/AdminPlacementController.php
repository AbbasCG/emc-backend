<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OralAssessmentSlotResource;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\OralAssessmentBooking;
use App\Models\OralAssessmentSlot;
use App\Models\PlacementTest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPlacementController extends Controller
{
    // ─── GET /api/admin/placement-tests ──────────────────────────────────────

    public function indexTests(): JsonResponse
    {
        $tests = PlacementTest::withCount('questions')->latest()->get();

        return response()->json([
            'success' => true,
            'data'    => $tests->map(fn ($t) => [
                'id'               => $t->id,
                'title'            => $t->title,
                'language'         => $t->language,
                'duration_minutes' => $t->duration_minutes,
                'total_questions'  => $t->total_questions,
                'questions_count'  => $t->questions_count,
                'is_active'        => $t->is_active,
            ]),
        ]);
    }

    // ─── POST /api/admin/courses/{course}/oral-assessment-slots ──────────────
    //
    // Admin creates available slots for a course so students can book.

    public function storeSlot(Request $request, Course $course): JsonResponse
    {
        $request->validate([
            'instructor_id' => ['required', 'integer', 'exists:instructors,id'],
            'starts_at'     => ['required', 'date', 'after:now'],
            'ends_at'       => ['required', 'date', 'after:starts_at'],
            'meeting_link'  => ['nullable', 'string', 'max:500'],
            'count'         => ['integer', 'min:1', 'max:50'],
        ]);

        $count = $request->integer('count', 1);
        $slots = [];

        $startsAt = \Carbon\Carbon::parse($request->starts_at);
        $endsAt   = \Carbon\Carbon::parse($request->ends_at);
        $duration = $startsAt->diffInMinutes($endsAt);

        for ($i = 0; $i < $count; $i++) {
            $slots[] = OralAssessmentSlot::create([
                'course_id'      => $course->id,
                'instructor_id'  => $request->instructor_id,
                'starts_at'      => $startsAt->copy()->addMinutes($i * $duration),
                'ends_at'        => $endsAt->copy()->addMinutes($i * $duration),
                'meeting_link'   => $request->meeting_link,
                'booking_status' => 'available',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "تم إنشاء {$count} موعد بنجاح.",
            'data'    => OralAssessmentSlotResource::collection(collect($slots)),
        ], 201);
    }

    // ─── GET /api/admin/courses/{course}/oral-assessment-slots ───────────────

    public function indexSlots(Request $request, Course $course): JsonResponse
    {
        $slots = OralAssessmentSlot::with('instructor', 'bookedBy', 'booking.user')
            ->forCourse($course->id)
            ->when($request->status, fn ($q) => $q->where('booking_status', $request->status))
            ->orderBy('starts_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $slots->map(fn ($s) => [
                'id'             => $s->id,
                'starts_at'      => $s->starts_at->toIso8601String(),
                'ends_at'        => $s->ends_at->toIso8601String(),
                'booking_status' => $s->booking_status,
                'meeting_link'   => $s->meeting_link,
                'instructor'     => ['id' => $s->instructor?->id, 'name' => $s->instructor?->name],
                'booked_by'      => $s->booking ? [
                    'id'   => $s->booking->user?->id,
                    'name' => $s->booking->user?->name,
                ] : null,
            ]),
            'meta'    => [
                'total'        => $slots->total(),
                'current_page' => $slots->currentPage(),
                'last_page'    => $slots->lastPage(),
            ],
        ]);
    }

    // ─── DELETE /api/admin/oral-assessment-slots/{slot} ──────────────────────

    public function destroySlot(OralAssessmentSlot $slot): JsonResponse
    {
        if ($slot->is_booked) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف موعد محجوز.',
            ], 422);
        }

        $slot->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف الموعد بنجاح.']);
    }

    // ─── GET /api/admin/oral-assessments ─────────────────────────────────────

    public function indexBookings(Request $request): JsonResponse
    {
        $bookings = OralAssessmentBooking::with(['slot', 'user', 'attempt', 'course', 'instructor'])
            ->when($request->course_id,   fn ($q) => $q->where('course_id', $request->course_id))
            ->when($request->status,      fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $bookings->map(fn ($b) => [
                'id'              => $b->id,
                'status'          => $b->status,
                'oral_score'      => $b->oral_score,
                'final_level'     => $b->final_level,
                'instructor_notes'=> $b->instructor_notes,
                'created_at'      => $b->created_at->toIso8601String(),
                'course'          => ['id' => $b->course?->id, 'title' => $b->course?->title],
                'student'         => ['id' => $b->user?->id, 'name' => $b->user?->name, 'email' => $b->user?->email],
                'instructor'      => ['id' => $b->instructor?->id, 'name' => $b->instructor?->name],
                'written_test'    => $b->attempt ? [
                    'score'           => $b->attempt->score,
                    'estimated_level' => $b->attempt->estimated_level,
                ] : null,
                'slot'            => $b->slot ? [
                    'starts_at' => $b->slot->starts_at->toIso8601String(),
                ] : null,
            ]),
            'meta'    => [
                'total'        => $bookings->total(),
                'current_page' => $bookings->currentPage(),
                'last_page'    => $bookings->lastPage(),
            ],
        ]);
    }
}
