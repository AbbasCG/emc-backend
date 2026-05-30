<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
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
            ->whereIn('status', RegistrationStatus::activeEnrollment())
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
