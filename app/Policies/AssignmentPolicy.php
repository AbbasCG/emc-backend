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

        return $this->isEnrolledLearner($user, $assignment);
    }

    /**
     * Submitting is something a LEARNER does: it needs an enrollment in the assignment's course, whatever the account's role.
     * (Admins and instructors may view every assignment, but that does not make them learners of it.)
     */
    public function submit(User $user, Assignment $assignment): bool
    {
        return $this->isEnrolledLearner($user, $assignment);
    }

    private function isEnrolledLearner(User $user, Assignment $assignment): bool
    {
        $courseId = $assignment->course_id ?? $assignment->workshop?->course_id;

        return $courseId && app(LmsEnrollmentService::class)->isEnrolledInCourse($user, $courseId);
    }
}
