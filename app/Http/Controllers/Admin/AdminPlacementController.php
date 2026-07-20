<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OralAssessmentSlotResource;
use App\Models\Course;
use App\Models\ExamViolation;
use App\Models\Instructor;
use App\Models\OralAssessmentBooking;
use App\Models\OralAssessmentSlot;
use App\Models\PlacementTest;
use App\Models\PlacementTestAttempt;
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

    // ─── GET /api/admin/placement-tests/attempts/{attempt}/violations ────────
    //
    // Violation timeline + risk score for a single attempt — the per-attempt
    // exam-integrity view. Risk score is a simple severity-weighted count
    // (low=1, medium=3, high=6), not a statistical model — good enough to sort
    // "needs review" attempts to the top without pretending to be a proctoring product.

    public function attemptViolations(PlacementTestAttempt $attempt): JsonResponse
    {
        $violations = $attempt->violations()
            ->with('question:id,question_text')
            ->orderBy('occurred_at')
            ->get();

        $weights = ['low' => 1, 'medium' => 3, 'high' => 6];
        $riskScore = $violations->sum(fn ($v) => $weights[$v->severity] ?? 1);

        $attempt->loadMissing('user:id,name,email');

        return response()->json([
            'success' => true,
            'data'    => [
                'attempt' => [
                    'id'               => $attempt->id,
                    'user'             => ['id' => $attempt->user?->id, 'name' => $attempt->user?->name, 'email' => $attempt->user?->email],
                    'status'           => $attempt->status,
                    'score'            => $attempt->score,
                    'total_questions'  => $attempt->total_questions,
                    'estimated_level'  => $attempt->estimated_level,
                    'started_at'       => $attempt->started_at?->toIso8601String(),
                    'submitted_at'     => $attempt->submitted_at?->toIso8601String(),
                ],
                'risk_score'       => $riskScore,
                'violation_count'  => $violations->count(),
                'violations'       => $violations->map(fn ($v) => [
                    'id'              => $v->id,
                    'violation_type'  => $v->violation_type,
                    'severity'        => $v->severity,
                    'meta'            => $v->meta,
                    'question'        => $v->question ? ['id' => $v->question->id, 'text' => $v->question->question_text] : null,
                    'ip_address'      => $v->ip_address,
                    'user_agent'      => $v->user_agent,
                    'occurred_at'     => $v->occurred_at->toIso8601String(),
                ]),
            ],
        ]);
    }

    // ─── GET /api/admin/placement-tests/violations/summary ───────────────────
    //
    // Cross-attempt summary — which attempts have violations, ranked by risk score.
    // Powers the admin "needs review" list.

    public function violationsSummary(Request $request): JsonResponse
    {
        $weights = ['low' => 1, 'medium' => 3, 'high' => 6];

        $rows = ExamViolation::query()
            ->selectRaw('placement_test_attempt_id, COUNT(*) as violation_count, GROUP_CONCAT(severity) as severities')
            ->whereNotNull('placement_test_attempt_id')
            ->when($request->course_id, function ($q) use ($request) {
                $q->whereHas('attempt', fn ($qq) => $qq->where('course_id', $request->course_id));
            })
            ->groupBy('placement_test_attempt_id')
            ->get();

        $attemptIds = $rows->pluck('placement_test_attempt_id');
        $attempts = PlacementTestAttempt::with('user:id,name,email', 'course:id,title')
            ->whereIn('id', $attemptIds)
            ->get()
            ->keyBy('id');

        $ranked = $rows->map(function ($row) use ($attempts, $weights) {
            $attempt = $attempts->get($row->placement_test_attempt_id);
            $severities = explode(',', (string) $row->severities);
            $riskScore = collect($severities)->sum(fn ($s) => $weights[$s] ?? 1);

            return [
                'attempt_id'      => $row->placement_test_attempt_id,
                'user'            => $attempt ? ['id' => $attempt->user?->id, 'name' => $attempt->user?->name] : null,
                'course'          => $attempt ? ['id' => $attempt->course?->id, 'title' => $attempt->course?->title] : null,
                'status'          => $attempt?->status,
                'violation_count' => (int) $row->violation_count,
                'risk_score'      => $riskScore,
            ];
        })->sortByDesc('risk_score')->values();

        return response()->json(['success' => true, 'data' => $ranked]);
    }
}
