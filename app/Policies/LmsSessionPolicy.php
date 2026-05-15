<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\LmsSession;
use App\Models\User;
use App\Services\LmsEnrollmentService;

class LmsSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, LmsSession $session): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $enrollment = app(LmsEnrollmentService::class);
        $courseId = $enrollment->sessionCourseId($session->course_id, $session->workshop_id);

        if ($courseId && $user->isStudent() && $enrollment->isEnrolledInCourse($user, $courseId)) {
            return true;
        }

        if ($user->isTeacher()) {
            $profile = $user->instructorProfile;
            if (! $profile) {
                return true;
            }
            if ($courseId) {
                $course = Course::query()->find($courseId);

                return $course && (int) $course->instructor_id === (int) $profile->id;
            }

            return (int) $session->instructor_id === (int) $profile->id;
        }

        return false;
    }

    public function updateAttendance(User $user, LmsSession $session): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isTeacher()) {
            return $this->teacherOwnsSession($user, $session);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, LmsSession $session): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, LmsSession $session): bool
    {
        return $user->isAdmin();
    }

    protected function teacherOwnsSession(User $user, LmsSession $session): bool
    {
        $profile = $user->instructorProfile;
        if (! $profile || ! $session->instructor_id) {
            return true;
        }

        return (int) $session->instructor_id === (int) $profile->id;
    }
}
