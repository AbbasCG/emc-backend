<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;
use App\Services\LmsEnrollmentService;

class AssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Assignment $assignment): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Assignment $assignment): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Assignment $assignment): bool
    {
        if ($user->isAdmin() || $user->isTeacher()) {
            return true;
        }

        $courseId = $assignment->course_id ?? $assignment->workshop?->course_id;
        if (! $courseId || ! $user->isStudent()) {
            return false;
        }

        return app(LmsEnrollmentService::class)->isEnrolledInCourse($user, $courseId);
    }

    public function submit(User $user, Assignment $assignment): bool
    {
        return $this->view($user, $assignment) && $user->isStudent();
    }
}
