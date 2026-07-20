<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Models\ClassGroupStudent;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Collection;

class LmsEnrollmentService
{
    /**
     * Course IDs the user is actively registered for (by user_id or email match).
     */
    public function enrolledCourseIds(User $user): Collection
    {
        return Registration::query()
            ->activeEnrollment()
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('email', $user->email);
            })
            ->pluck('course_id')
            ->unique()
            ->values();
    }

    public function isEnrolledInCourse(User $user, int $courseId): bool
    {
        return $this->enrolledCourseIds($user)->contains($courseId);
    }

    /**
     * course_id => class_group_id for every course the student is actively
     * assigned to a class group in. A course absent from this map means the
     * student sees only unscoped (course-wide) content for it — either they
     * haven't been assigned a class yet, or the course has no class groups.
     */
    public function classGroupIdsByCourse(User $user): Collection
    {
        return ClassGroupStudent::query()
            ->whereIn('status', ClassGroupStudent::ACTIVE_STATUSES)
            ->where('user_id', $user->id)
            ->with('classGroup:id,course_id')
            ->get()
            ->filter(fn (ClassGroupStudent $cgs) => $cgs->classGroup !== null)
            ->pluck('classGroup.id', 'classGroup.course_id');
    }

    public function sessionCourseId(?int $courseId, ?int $workshopId): ?int
    {
        if ($courseId) {
            return $courseId;
        }
        if ($workshopId) {
            return \App\Models\Workshop::query()->whereKey($workshopId)->value('course_id');
        }

        return null;
    }
}
