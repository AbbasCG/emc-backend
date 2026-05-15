<?php

namespace App\Policies;

use App\Models\AssignmentSubmission;
use App\Models\User;

class AssignmentSubmissionPolicy
{
    public function review(User $user, AssignmentSubmission $submission): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isTeacher()) {
            return false;
        }

        $assignment = $submission->assignment;
        $courseId = $assignment->course_id ?? $assignment->workshop?->course_id;
        $profile = $user->instructorProfile;

        if (! $courseId || ! $profile) {
            return true;
        }

        $course = \App\Models\Course::query()->find($courseId);

        return $course && (int) $course->instructor_id === (int) $profile->id;
    }
}
