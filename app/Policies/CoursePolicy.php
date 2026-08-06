<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    public function viewAny(?User $user): bool
    {
        return true; // public catalogue
    }

    public function view(?User $user, Course $course): bool
    {
        return true; // public catalogue
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    public function update(User $user, Course $course): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isTeacher()) {
            $profile = $user->instructorProfile;

            return $profile && (int) $course->instructor_id === (int) $profile->id;
        }

        return false;
    }

    public function delete(User $user, Course $course): bool
    {
        return $user->isAdmin();
    }
}
