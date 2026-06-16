<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\OralAssessmentBooking;
use App\Models\PlacementTestAttempt;
use App\Models\Registration;
use App\Services\NotificationService;
use App\Services\PlacementProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstructorClassController extends Controller
{
    public function __construct(private readonly PlacementProgressService $placement) {}

    // ─── GET /api/instructor/classes ─────────────────────────────────────────
    // All class groups for courses assigned to the instructor.

    public function index(Request $request): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();
        $courseIds  = $instructor
            ? Course::where('instructor_id', $instructor->id)->pluck('id')->all()
            : [];

        $groups = ClassGroup::with(['course', 'activeStudents'])
            ->whereIn('course_id', $courseIds)
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', (int) $request->course_id))
            ->when($request->filled('level_code'), fn ($q) => $q->where('level_code', $request->level_code))
            ->latest()
            ->get()
            ->map(fn ($g) => $this->shapeGroup($g));

        return response()->json(['success' => true, 'data' => $groups]);
    }

    // ─── POST /api/instructor/classes ────────────────────────────────────────
    // Create a new class group.

    public function store(Request $request): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();

        $request->validate([
            'course_id'     => ['required', 'integer', 'exists:courses,id'],
            'name'          => ['required', 'string', 'max:120'],
            'level_code'    => ['nullable', 'string', 'in:Starter,A1,A2,B1,B2,C1'],
            'capacity'      => ['nullable', 'integer', 'min:1', 'max:200'],
            'start_date'    => ['nullable', 'date'],
            'schedule_day'  => ['nullable', 'string'],
            'schedule_time' => ['nullable', 'date_format:H:i'],
            'location_type' => ['nullable', 'string', 'in:online,in_person,hybrid'],
            'meeting_link'  => ['nullable', 'string', 'max:500'],
        ]);

        if ($instructor) {
            $course = Course::find($request->course_id);
            if (! $course || (int) $course->instructor_id !== $instructor->id) {
                abort(403, 'ليس لديك صلاحية إنشاء مجموعات لهذه الدورة.');
            }
        }

        $group = ClassGroup::create([
            'course_id'     => $request->course_id,
            'instructor_id' => $instructor?->id,
            'name'          => $request->name,
            'level_code'    => $request->level_code,
            'capacity'      => $request->capacity ?? 20,
            'start_date'    => $request->start_date,
            'schedule_day'  => $request->schedule_day,
            'schedule_time' => $request->schedule_time,
            'location_type' => $request->location_type ?? 'online',
            'meeting_link'  => $request->meeting_link,
            'status'        => 'draft',
            'created_by'    => $request->user()->id,
        ]);

        $group->load(['course', 'activeStudents']);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء المجموعة بنجاح.',
            'data'    => $this->shapeGroup($group),
        ], 201);
    }

    // ─── PATCH /api/instructor/classes/{group} ───────────────────────────────
    // Update class group status/details.

    public function update(Request $request, ClassGroup $group): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();
        if ($instructor && (int) $group->instructor_id !== $instructor->id) {
            abort(403, 'ليس لديك صلاحية تعديل هذه المجموعة.');
        }

        $request->validate([
            'name'          => ['sometimes', 'string', 'max:120'],
            'level_code'    => ['sometimes', 'nullable', 'string', 'in:Starter,A1,A2,B1,B2,C1'],
            'capacity'      => ['sometimes', 'integer', 'min:1', 'max:200'],
            'start_date'    => ['sometimes', 'nullable', 'date'],
            'schedule_day'  => ['sometimes', 'nullable', 'string'],
            'schedule_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'location_type' => ['sometimes', 'string', 'in:online,in_person,hybrid'],
            'meeting_link'  => ['sometimes', 'nullable', 'string', 'max:500'],
            'status'        => ['sometimes', 'string', 'in:draft,ready,active,completed,archived'],
        ]);

        $group->update($request->only([
            'name', 'level_code', 'capacity', 'start_date',
            'schedule_day', 'schedule_time', 'location_type', 'meeting_link', 'status',
        ]));

        $group->load(['course', 'activeStudents']);

        return response()->json(['success' => true, 'data' => $this->shapeGroup($group)]);
    }

    // ─── DELETE /api/instructor/classes/{group} ──────────────────────────────

    public function destroy(Request $request, ClassGroup $group): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();
        if ($instructor && (int) $group->instructor_id !== $instructor->id) {
            abort(403, 'ليس لديك صلاحية حذف هذه المجموعة.');
        }

        if ($group->activeStudents()->count() > 0) {
            return response()->json(['success' => false, 'message' => 'لا يمكن حذف مجموعة تحتوي على طلاب.'], 422);
        }

        $group->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف المجموعة.']);
    }

    // ─── GET /api/instructor/courses/{course}/students ───────────────────────
    // All enrolled students for a course (registrations, no placement filter).

    public function courseStudents(Request $request, Course $course): JsonResponse
    {
        $user       = $request->user();
        $instructor = Instructor::where('user_id', $user->id)->first();

        if ($instructor && (int) $course->instructor_id !== $instructor->id) {
            abort(403, 'ليس لديك صلاحية الوصول إلى هذه الدورة.');
        }

        $registrations = Registration::with('user:id,name,email,avatar')
            ->where('course_id', $course->id)
            ->whereNotIn('status', ['Rejected', 'Cancelled', 'rejected', 'cancelled'])
            ->whereNotNull('user_id')
            ->get();

        $userIds = $registrations->pluck('user_id')->filter()->all();

        // Which users are already assigned to a class group for this course
        $assignedUserIds = ClassGroupStudent::whereHas('classGroup', fn ($q) => $q->where('course_id', $course->id))
            ->whereIn('status', ['assigned', 'completed'])
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->flip();

        $data = $registrations->map(fn ($reg) => [
            'student_id'          => $reg->user_id,
            'student_name'        => $reg->user?->name ?? $reg->full_name,
            'student_email'       => $reg->user?->email ?? $reg->email,
            'student_phone'       => $reg->user?->phone ?? $reg->phone ?? null,
            'registration_status' => strtolower($reg->status),
            'avatar_url'          => $reg->user?->avatar_url ?? null,
            'is_assigned'         => isset($assignedUserIds[$reg->user_id]),
            // Null placement fields so frontend ClassAssignmentStudent type is satisfied
            'written_score'       => null,
            'total_questions'     => null,
            'percentage'          => null,
            'written_level'       => null,
            'oral_score'          => null,
            'final_level'         => null,
            'instructor_notes'    => null,
            'placement_status'    => 'not_started',
            'attempt_id'          => null,
            'booking_id'          => null,
        ])->values();

        return response()->json([
            'success' => true,
            'data'    => $data,
            'meta'    => ['total' => $data->count()],
        ]);
    }

    // ─── GET /api/instructor/courses/{course}/classes ────────────────────────
    // Class groups for a specific course.

    public function courseGroups(Request $request, Course $course): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();
        if ($instructor && (int) $course->instructor_id !== $instructor->id) {
            abort(403, 'ليس لديك صلاحية الوصول إلى هذه الدورة.');
        }

        $groups = ClassGroup::with(['activeStudents'])
            ->where('course_id', $course->id)
            ->latest()
            ->get()
            ->map(fn ($g) => $this->shapeGroup($g));

        return response()->json(['success' => true, 'data' => $groups]);
    }

    // ─── GET /api/instructor/courses/{course}/class-assignment/students ──────
    // Students ready for class assignment (written + oral completed).

    public function assignmentStudents(Request $request, Course $course): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();
        if ($instructor && (int) $course->instructor_id !== $instructor->id) {
            abort(403, 'ليس لديك صلاحية الوصول إلى هذه الدورة.');
        }

        $registrations = Registration::with('user')
            ->where('course_id', $course->id)
            ->whereNotIn('status', ['Rejected', 'Cancelled'])
            ->get();

        $userIds = $registrations->pluck('user_id')->filter()->unique()->values()->all();

        $attempts = PlacementTestAttempt::where('course_id', $course->id)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy(fn ($a) => (string) $a->user_id);

        $bookings = OralAssessmentBooking::with('slot')
            ->where('course_id', $course->id)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy(fn ($b) => (string) $b->user_id);

        // Already assigned students
        $assignedUserIds = ClassGroupStudent::whereHas('classGroup', fn ($q) => $q->where('course_id', $course->id))
            ->whereIn('status', ['assigned', 'completed'])
            ->pluck('user_id')
            ->flip();

        $students = $registrations->map(function ($reg) use ($attempts, $bookings, $assignedUserIds) {
            $attempt = $reg->user_id ? $attempts->get((string) $reg->user_id) : null;
            $booking = $reg->user_id ? $bookings->get((string) $reg->user_id) : null;
            $prog    = $this->placement->progress($attempt, $booking);

            if (! $prog) return null;

            $status = $prog['status'];
            // Only include students who have completed at least the written test
            if (! in_array($status, [
                PlacementProgressService::STATUS_WRITTEN_COMPLETED,
                PlacementProgressService::STATUS_ORAL_BOOKED,
                PlacementProgressService::STATUS_ORAL_COMPLETED,
                PlacementProgressService::STATUS_COMPLETED,
            ], true)) {
                return null;
            }

            return [
                'student_id'       => $reg->user_id,
                'student_name'     => $reg->user?->name ?? $reg->full_name,
                'student_email'    => $reg->user?->email ?? $reg->email,
                'written_score'    => $prog['written_test']['score'] ?? null,
                'total_questions'  => $prog['written_test']['total_questions'] ?? null,
                'percentage'       => $prog['written_test']['percentage'] ?? null,
                'written_level'    => $prog['written_test']['estimated_level'] ?? null,
                'oral_score'       => $prog['oral_assessment']['oral_score'] ?? null,
                'final_level'      => $prog['oral_assessment']['final_level'] ?? null,
                'instructor_notes' => $prog['oral_assessment']['instructor_notes'] ?? null,
                'placement_status' => $status,
                'attempt_id'       => $attempt?->id,
                'booking_id'       => $booking?->id,
                'is_assigned'      => isset($assignedUserIds[$reg->user_id]),
            ];
        })->filter()->values();

        return response()->json(['success' => true, 'data' => $students]);
    }

    // ─── POST /api/instructor/classes/{group}/students ───────────────────────
    // Assign a student to a class group.

    public function addStudent(Request $request, ClassGroup $group): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();
        if ($instructor && (int) $group->instructor_id !== $instructor->id) {
            abort(403, 'ليس لديك صلاحية إضافة طلاب لهذه المجموعة.');
        }

        $request->validate([
            'user_id'                   => ['required', 'integer', 'exists:users,id'],
            'placement_attempt_id'      => ['nullable', 'integer'],
            'oral_assessment_booking_id'=> ['nullable', 'integer'],
            'notes'                     => ['nullable', 'string', 'max:500'],
        ]);

        $activeCount = $group->activeStudents()->count();
        if ($activeCount >= $group->capacity) {
            return response()->json(['success' => false, 'message' => 'وصلت المجموعة إلى الحد الأقصى للطلاب.'], 422);
        }

        $existing = ClassGroupStudent::where('class_group_id', $group->id)
            ->where('user_id', $request->user_id)
            ->first();

        if ($existing) {
            $existing->update(['status' => 'assigned', 'assigned_by' => $request->user()->id, 'assigned_at' => now()]);
            $this->sendClassAssignmentNotification($request->user_id, $group);
            return response()->json(['success' => true, 'message' => 'تم تحديث تعيين الطالب.']);
        }

        ClassGroupStudent::create([
            'class_group_id'             => $group->id,
            'user_id'                    => $request->user_id,
            'placement_attempt_id'       => $request->placement_attempt_id,
            'oral_assessment_booking_id' => $request->oral_assessment_booking_id,
            'assigned_by'                => $request->user()->id,
            'assigned_at'                => now(),
            'status'                     => 'assigned',
            'notes'                      => $request->notes,
        ]);

        $this->sendClassAssignmentNotification($request->user_id, $group);

        return response()->json(['success' => true, 'message' => 'تم تعيين الطالب إلى المجموعة.'], 201);
    }

    // ─── DELETE /api/instructor/classes/{group}/students/{user} ─────────────

    public function removeStudent(Request $request, ClassGroup $group, int $userId): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();
        if ($instructor && (int) $group->instructor_id !== $instructor->id) {
            abort(403, 'ليس لديك صلاحية إزالة طلاب من هذه المجموعة.');
        }

        ClassGroupStudent::where('class_group_id', $group->id)
            ->where('user_id', $userId)
            ->update(['status' => 'removed']);

        return response()->json(['success' => true, 'message' => 'تم إزالة الطالب من المجموعة.']);
    }

    // ─── GET /api/instructor/classes/{group}/students ────────────────────────
    // All active students enrolled in a class group.

    public function groupStudents(Request $request, ClassGroup $group): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();
        if ($instructor && (int) $group->instructor_id !== $instructor->id) {
            abort(403, 'ليس لديك صلاحية الوصول إلى هذه المجموعة.');
        }

        $students = $group->students()
            ->whereIn('status', ['assigned', 'completed'])
            ->with('user:id,name,email,avatar')
            ->get()
            ->map(fn (ClassGroupStudent $s) => [
                'id'         => $s->user_id,
                'name'       => $s->user?->name,
                'email'      => $s->user?->email,
                'avatar'     => $s->user?->avatar ?? null,
                'status'     => $s->status,
                'notes'      => $s->notes,
                'assigned_at'=> $s->assigned_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'group_id'       => $group->id,
                'group_name'     => $group->name,
                'course_id'      => $group->course_id,
                'students_count' => $students->count(),
                'students'       => $students->values(),
            ],
        ]);
    }

    // ─── Notification helper ─────────────────────────────────────────────────

    private function sendClassAssignmentNotification(int $studentUserId, ClassGroup $group): void
    {
        $group->loadMissing(['course', 'instructor.user']);

        NotificationService::classGroupAssigned(
            studentUserId: $studentUserId,
            classGroupName: $group->name,
            courseTitle: $group->course?->title ?? '',
            instructorName: $group->instructor?->user?->name ?? $group->instructor?->name,
            scheduleDay: $group->schedule_day,
            scheduleTime: $group->schedule_time,
            locationType: $group->location_type,
        );
    }

    // ─── Shape helper ────────────────────────────────────────────────────────

    private function shapeGroup(ClassGroup $g): array
    {
        $enrolled = $g->relationLoaded('activeStudents') ? $g->activeStudents->count() : 0;

        return [
            'id'            => $g->id,
            'course_id'     => $g->course_id,
            'course_title'  => $g->course?->title,
            'instructor_id' => $g->instructor_id,
            'level_code'    => $g->level_code,
            'name'          => $g->name,
            'capacity'      => $g->capacity,
            'enrolled'      => $enrolled,
            'remaining'     => max(0, $g->capacity - $enrolled),
            'start_date'    => $g->start_date?->toDateString(),
            'schedule_day'  => $g->schedule_day,
            'schedule_time' => $g->schedule_time ? substr($g->schedule_time, 0, 5) : null,
            'location_type' => $g->location_type,
            'meeting_link'  => $g->meeting_link,
            'status'        => $g->status,
            'created_at'    => $g->created_at->toIso8601String(),
        ];
    }
}
