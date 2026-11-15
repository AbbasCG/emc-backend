<?php

namespace App\Support;

use App\Models\AssignmentSubmission;

/**
 * Who may read an assignment submission's files. Moved unchanged out of SubmissionDownloadController so the legacy
 * download endpoints and the per-file endpoint can never drift apart:
 *  - admin / super_admin: any submission
 *  - the submitting student: their own
 *  - an instructor: only submissions of a course (or workshop's course) they teach
 */
class SubmissionAccess
{
    public static function canAccess($user, AssignmentSubmission $submission): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        if ($submission->user_id === $user->id) {
            return true;
        }

        if ($user->isTeacher()) {
            $submission->loadMissing('assignment.course.instructor', 'assignment.workshop.course.instructor');

            $assignment = $submission->assignment;
            $course     = $assignment?->course ?? $assignment?->workshop?->course;
            $profile    = $user->instructorProfile;

            return $profile && $course && $course->instructor_id === $profile->id;
        }

        return false;
    }
}
