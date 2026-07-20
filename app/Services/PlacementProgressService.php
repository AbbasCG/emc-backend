<?php

namespace App\Services;

use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\OralAssessmentBooking;
use App\Models\PlacementTestAttempt;
use App\Models\Registration;
use App\Enums\RegistrationStatus;

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

    /**
     * Full oral-assessment detail — rubric breakdown, evaluator, timestamps,
     * and edit history — for the instructor's "view full assessment" modal.
     * Requires $booking loaded with: slot, instructor.user, editedBy.
     * Returns null when no evaluation has been recorded yet.
     */
    public function oralAssessmentFullShape(?OralAssessmentBooking $booking): ?array
    {
        if (! $booking || ! $booking->evaluated_at) {
            return null;
        }

        $slot       = $booking->relationLoaded('slot')       ? $booking->slot       : null;
        $instructor = $booking->relationLoaded('instructor') ? $booking->instructor : null;
        $tz         = config('app.booking_timezone', 'Europe/Amsterdam');

        $durationMinutes = ($slot?->starts_at && $slot?->ends_at)
            ? $slot->starts_at->diffInMinutes($slot->ends_at)
            : null;

        $rubric = [
            ['key' => 'pronunciation', 'label' => 'النطق',        'score' => $booking->pronunciation_score, 'max' => 10],
            ['key' => 'grammar',       'label' => 'القواعد',       'score' => $booking->grammar_score,       'max' => 10],
            ['key' => 'vocabulary',    'label' => 'المفردات',      'score' => $booking->vocabulary_score,    'max' => 10],
            ['key' => 'fluency',       'label' => 'الطلاقة',       'score' => $booking->fluency_score,       'max' => 10],
            ['key' => 'comprehension', 'label' => 'الاستيعاب',     'score' => $booking->comprehension_score, 'max' => 10],
            ['key' => 'confidence',    'label' => 'الثقة بالنفس',  'score' => $booking->confidence_score,    'max' => 10],
        ];

        return [
            'id'               => $booking->id,
            'status'           => $booking->status,
            'oral_score'       => $booking->oral_score,
            'oral_score_max'   => 100,
            'final_level'      => $booking->final_level,
            'rubric'           => $rubric,
            'notes'            => [
                'reason'          => $booking->instructor_notes,
                'strengths'       => $booking->strengths,
                'weaknesses'      => $booking->weaknesses,
                'recommendations' => $booking->recommendations,
            ],
            'interview'        => [
                'starts_at'        => $slot?->starts_at?->copy()->setTimezone($tz)->toIso8601String(),
                'ends_at'          => $slot?->ends_at?->copy()->setTimezone($tz)->toIso8601String(),
                'duration_minutes' => $durationMinutes,
            ],
            'evaluator'        => [
                'id'   => $instructor?->id,
                'name' => $instructor?->user?->name ?? $instructor?->name,
            ],
            'system'           => [
                'evaluated_at'  => $booking->evaluated_at?->copy()->setTimezone($tz)->toIso8601String(),
                'last_modified' => $booking->updated_at?->copy()->setTimezone($tz)->toIso8601String(),
                'approval_status' => $booking->final_level ? 'approved' : 'pending',
            ],
            'history'          => $booking->edited_at ? [
                'previous_oral_score'  => $booking->previous_oral_score,
                'previous_final_level' => $booking->previous_final_level,
                'edited_by'            => $booking->editedBy?->name,
                'edited_at'            => $booking->edited_at?->copy()->setTimezone($tz)->toIso8601String(),
            ] : null,
        ];
    }

    /** Shape a student's class-group assignment for a specific course, or the "waiting" state. */
    public function classAssignmentShape(?ClassGroupStudent $cgs): array
    {
        if (! $cgs || ! in_array($cgs->status, ClassGroupStudent::ACTIVE_STATUSES, true)) {
            return [
                'status'           => 'waiting_class_assignment',
                'class_group_id'   => null,
                'class_name'       => null,
                'level_code'       => null,
                'assigned_at'      => null,
                'instructor_name'  => null,
                'assigned_by_name' => null,
                'method'           => null,
                'reason_details'   => [],
            ];
        }

        $group = $cgs->relationLoaded('classGroup') ? $cgs->classGroup : null;
        $instructor = $group?->relationLoaded('instructor') ? $group->instructor : null;
        $assignedByUser = $cgs->relationLoaded('assignedBy') ? $cgs->assignedBy : null;

        // ClassAssignmentService::autoAssign() writes assigned_by = null (see
        // that file for the full matching algorithm) — the four rules below
        // describe that exact algorithm, not an invented explanation. Any
        // non-null assigned_by came from the manual InstructorClassController
        // addStudent() endpoint instead.
        $isAutomatic = $cgs->assigned_by === null;

        return [
            'status'           => 'assigned',
            'class_group_id'   => $cgs->class_group_id,
            'class_name'       => $group?->name,
            'level_code'       => $group?->level_code,
            'assigned_at'      => $cgs->assigned_at?->toIso8601String(),
            'instructor_name'  => $instructor?->user?->name ?? $instructor?->name,
            'assigned_by_name' => $assignedByUser?->name,
            'method'           => $isAutomatic ? 'automatic' : 'manual',
            'reason_details'   => $isAutomatic
                ? ['level_match', 'same_course', 'capacity_available', 'lowest_occupancy']
                : [],
        ];
    }

    /**
     * Single reusable source of truth for "students of a course, with full
     * placement progress + class assignment" — used by both the placement-tests
     * page and the course-students page so they can never show different data
     * for the same student. Avoids N+1 via bulk-keyed maps, same pattern as
     * progress()'s callers already used per-controller before this existed.
     *
     * @return array{students: array<int, array>, status_counts: array<string,int>}
     */
    public function studentsForCourse(Course $course): array
    {
        $registrations = Registration::with('user')
            ->where('course_id', $course->id)
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->get();

        $userIds = $registrations->pluck('user_id')->filter()->unique()->values()->all();

        $attempts = PlacementTestAttempt::where('course_id', $course->id)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy(fn ($a) => (string) $a->user_id);

        $bookings = OralAssessmentBooking::with(['slot', 'instructor.user', 'editedBy'])
            ->where('course_id', $course->id)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy(fn ($b) => (string) $b->user_id);

        // Ticket 2 Phase 5: if a student somehow has multiple active
        // memberships for this course (structurally rare, not impossible —
        // addStudent() upserts by class_group_id, so it can only happen
        // across two different class groups), never depend on unordered DB
        // row order. Sort so the most recently assigned membership is kept
        // when keyBy() collapses duplicates by user_id — the same
        // deterministic tie-break used by CertificateEligibilityService's
        // resolveClassGroupForUser().
        $classAssignments = ClassGroupStudent::with(['classGroup.instructor.user', 'assignedBy'])
            ->whereHas('classGroup', fn ($q) => $q->where('course_id', $course->id))
            ->whereIn('user_id', $userIds)
            ->orderBy('assigned_at')
            ->orderBy('id')
            ->get()
            ->keyBy(fn ($cgs) => (string) $cgs->user_id);

        $statusCounts = array_fill_keys([
            self::STATUS_NOT_STARTED, self::STATUS_IN_PROGRESS, self::STATUS_WRITTEN_COMPLETED,
            self::STATUS_ORAL_BOOKED, self::STATUS_ORAL_COMPLETED, self::STATUS_COMPLETED,
        ], 0);

        $students = $registrations->map(function ($reg) use ($attempts, $bookings, $classAssignments, &$statusCounts) {
            $key      = $reg->user_id ? (string) $reg->user_id : null;
            $attempt  = $key ? $attempts->get($key) : null;
            $booking  = $key ? $bookings->get($key) : null;
            $cgs      = $key ? $classAssignments->get($key) : null;
            $progress = $this->progress($attempt, $booking);

            if (isset($statusCounts[$progress['status']])) {
                $statusCounts[$progress['status']]++;
            }

            return [
                'student_id'          => $reg->user_id,
                'student_name'        => $reg->user?->name ?? $reg->full_name,
                'student_email'       => $reg->user?->email ?? $reg->email,
                'student_phone'       => $reg->user?->phone ?? $reg->phone ?? null,
                'avatar_url'          => $reg->user?->avatar_url ?? null,
                'enrollment_status'   => strtolower($reg->status),
                'registration_status' => strtolower($reg->status),
                'status_label'        => $this->statusLabel($progress['status']),
                // Flat IDs — normalizer uses these directly before falling back to nested progress
                'attempt_id'          => $attempt?->id,
                'booking_id'          => $booking?->id,
                // Flat fields so frontend normalizers don't need placement_progress nesting
                'placement_status'    => $progress['status'],
                'written_score'       => $progress['written_test']['score'] ?? null,
                'total_questions'     => $progress['written_test']['total_questions'] ?? null,
                'percentage'          => $progress['written_test']['percentage'] ?? null,
                'written_level'       => $progress['written_test']['estimated_level'] ?? null,
                'oral_booking_at'     => $progress['oral_assessment']['starts_at'] ?? null,
                'oral_score'          => $progress['oral_assessment']['oral_score'] ?? null,
                'instructor_notes'    => $progress['oral_assessment']['instructor_notes'] ?? null,
                'notes'               => $progress['oral_assessment']['instructor_notes'] ?? null,
                'final_level'         => $progress['oral_assessment']['final_level'] ?? null,
                'placement_progress'  => $progress,
                'oral_assessment_full' => $this->oralAssessmentFullShape($booking),
                // Class assignment — the same shape everywhere it's surfaced.
                'is_assigned'         => $cgs !== null && in_array($cgs->status, ClassGroupStudent::ACTIVE_STATUSES, true),
                'class_assignment'    => $this->classAssignmentShape($cgs),
            ];
        })->values()->all();

        return ['students' => $students, 'status_counts' => $statusCounts];
    }

    /**
     * Ticket 2 Phase 4: all students across every course owned by an
     * instructor, in the exact per-student shape studentsForCourse()
     * already produces — reused per-course rather than re-implemented, so
     * this can never disagree with the course-scoped endpoints about the
     * same student. Bounded by course count, not student count: one
     * studentsForCourse() call (itself already N+1-safe) per course.
     *
     * @return array<int, array>
     */
    public function studentsForInstructor(\App\Models\Instructor $instructor): array
    {
        $courses = Course::where('instructor_id', $instructor->id)->get();

        $all = [];
        foreach ($courses as $course) {
            foreach ($this->studentsForCourse($course)['students'] as $row) {
                $row['course_id']    = $course->id;
                $row['course_title'] = $course->title;
                $all[] = $row;
            }
        }

        return $all;
    }

    /**
     * Ticket 2 Phase 4: students belonging to one specific ClassGroup, in
     * the same canonical shape as studentsForCourse() — filters that
     * method's output down to this class's active members rather than
     * re-deriving written/oral/class-assignment shapes separately.
     *
     * @return array<int, array>
     */
    public function studentsForClassGroup(\App\Models\ClassGroup $group): array
    {
        $activeMemberIds = ClassGroupStudent::where('class_group_id', $group->id)
            ->whereIn('status', ClassGroupStudent::ACTIVE_STATUSES)
            ->pluck('user_id')
            ->all();

        if (empty($activeMemberIds) || ! $group->course) {
            return [];
        }

        return array_values(array_filter(
            $this->studentsForCourse($group->course)['students'],
            fn ($row) => in_array($row['student_id'], $activeMemberIds, true)
        ));
    }

    /**
     * Ticket 2 Phase 4: canonical single-student summary for one course —
     * the same row studentsForCourse() would produce for this user, not a
     * separately-derived shape. Returns null if the user has no active
     * registration in the course.
     */
    public function studentSummary(int $userId, Course $course): ?array
    {
        foreach ($this->studentsForCourse($course)['students'] as $row) {
            if ($row['student_id'] === $userId) {
                return $row;
            }
        }

        return null;
    }
}
