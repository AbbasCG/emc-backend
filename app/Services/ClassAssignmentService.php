<?php

namespace App\Services;

use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\ClassGroupStudentHistory;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Automatic class-group assignment, triggered once a student's final level is
 * approved. Matches by level_code, skips full/inactive groups, prefers the
 * group with the lowest current occupancy. If no matching group has room, the
 * student is simply left unassigned — "waiting for class assignment" is not a
 * stored status, it is the absence of an active ClassGroupStudent row (same
 * convention StudentDashboardController already uses).
 */
class ClassAssignmentService
{
    private const ASSIGNABLE_GROUP_STATUSES = ['ready', 'active'];

    /**
     * @return array{assigned: bool, class_group_student: ?ClassGroupStudent, reason: string}
     */
    public function autoAssign(
        User $student,
        Course $course,
        string $level,
        ?int $placementAttemptId = null,
        ?int $oralAssessmentBookingId = null,
    ): array {
        // Already actively assigned to a group for this course — do not double-assign.
        $alreadyAssigned = ClassGroupStudent::whereIn('status', ClassGroupStudent::ACTIVE_STATUSES)
            ->where('user_id', $student->id)
            ->whereHas('classGroup', fn ($q) => $q->where('course_id', $course->id))
            ->exists();

        if ($alreadyAssigned) {
            return ['assigned' => false, 'class_group_student' => null, 'reason' => 'already_assigned'];
        }

        $group = $this->findBestMatchingGroup($course->id, $level);

        if (! $group) {
            return ['assigned' => false, 'class_group_student' => null, 'reason' => 'no_matching_group'];
        }

        $cgs = DB::transaction(function () use ($group, $student, $placementAttemptId, $oralAssessmentBookingId) {
            // Re-check capacity under lock to close the race window the manual
            // addStudent() endpoint has today.
            $locked = ClassGroup::where('id', $group->id)->lockForUpdate()->first();
            $activeCount = $locked->activeStudents()->count();
            if ($activeCount >= $locked->capacity) {
                return null;
            }

            $row = ClassGroupStudent::create([
                'class_group_id'             => $locked->id,
                'user_id'                    => $student->id,
                'placement_attempt_id'       => $placementAttemptId,
                'oral_assessment_booking_id' => $oralAssessmentBookingId,
                'assigned_by'                => null, // null = system/automatic
                'assigned_at'                => now(),
                'status'                     => 'assigned',
                'notes'                      => 'تعيين تلقائي بناءً على المستوى المعتمد.',
            ]);

            ClassGroupStudentHistory::create([
                'class_group_student_id' => $row->id,
                'user_id'                => $student->id,
                'from_class_group_id'    => null,
                'to_class_group_id'      => $locked->id,
                'action'                 => 'assigned',
                'reason'                 => 'automatic',
                'changed_by'             => null,
                'changed_at'             => now(),
            ]);

            return $row;
        });

        if (! $cgs) {
            // Lost the capacity race between findBestMatchingGroup() and the
            // locked re-check — try the next-best group once.
            $retry = $this->findBestMatchingGroup($course->id, $level, excludeGroupId: $group->id);
            if (! $retry) {
                return ['assigned' => false, 'class_group_student' => null, 'reason' => 'capacity_race'];
            }

            return $this->autoAssign($student, $course, $level, $placementAttemptId, $oralAssessmentBookingId);
        }

        $this->notify($cgs, $student, $course);

        return ['assigned' => true, 'class_group_student' => $cgs, 'reason' => 'assigned'];
    }

    private function findBestMatchingGroup(int $courseId, string $level, ?int $excludeGroupId = null): ?ClassGroup
    {
        return ClassGroup::withCount('activeStudents')
            ->where('course_id', $courseId)
            ->where('level_code', $level)
            ->whereIn('status', self::ASSIGNABLE_GROUP_STATUSES)
            ->when($excludeGroupId, fn ($q) => $q->where('id', '!=', $excludeGroupId))
            ->get()
            ->filter(fn (ClassGroup $g) => $g->active_students_count < $g->capacity)
            ->sortBy('active_students_count')
            ->first();
    }

    private function notify(ClassGroupStudent $cgs, User $student, Course $course): void
    {
        $group = $cgs->classGroup()->with('instructor.user')->first();

        NotificationService::classGroupAssigned(
            $student->id,
            $group->name,
            $course->title,
            $group->instructor?->user?->name ?? $group->instructor?->name,
            $group->schedule_day,
            $group->schedule_time,
            $group->location_type,
        );

        $instructorUserId = $group->instructor?->user_id;
        if ($instructorUserId) {
            NotificationService::classGroupStudentAssignedInstructor(
                $instructorUserId,
                $student->name,
                $group->name,
                $course->title,
            );
        }
    }
}
