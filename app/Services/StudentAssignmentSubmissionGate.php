<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\User;

/**
 * Who may submit to an assignment. Extracted verbatim from StudentAssignmentController::submit() so the request
 * object (which validates the uploaded files) and the controller share one rule and the 403 still wins over a 422.
 *
 * Submitting is something a LEARNER does: it needs an enrollment in the assignment's course, for every account,
 * administrators included, and a course-enrolled student outside the assigned class group must not be able to submit
 * by posting directly to a known assignment ID.
 */
class StudentAssignmentSubmissionGate
{
    public function __construct(private readonly LmsEnrollmentService $enrollment)
    {
    }

    public function allows(User $user, Assignment $assignment): bool
    {
        $courseIds = $this->enrollment->enrolledCourseIds($user);

        $assignment->loadMissing('workshop');
        $courseId = $assignment->course_id
            ?? $assignment->workshop?->course_id;

        $accessible = $courseId && $courseIds->contains($courseId);

        if ($accessible && $assignment->class_group_id) {
            $myClassGroupIds = $this->enrollment->classGroupIdsByCourse($user);
            $accessible = $myClassGroupIds->get($courseId) === $assignment->class_group_id;
        }

        return (bool) $accessible;
    }
}
