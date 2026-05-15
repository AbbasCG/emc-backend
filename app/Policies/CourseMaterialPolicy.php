<?php

namespace App\Policies;

use App\Models\CourseMaterial;
use App\Models\User;
use App\Services\LmsEnrollmentService;

class CourseMaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, CourseMaterial $material): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, CourseMaterial $material): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, CourseMaterial $material): bool
    {
        if ($user->isAdmin() || $user->isTeacher()) {
            return true;
        }

        $courseId = $material->course_id ?? $material->workshop?->course_id;
        if (! $courseId || ! $user->isStudent()) {
            return false;
        }

        return app(LmsEnrollmentService::class)->isEnrolledInCourse($user, $courseId);
    }
}
