<?php

namespace App\Policies;

use App\Models\ClassGroupStudent;
use App\Models\Instructor;
use App\Models\LmsSession;
use App\Models\User;
use App\Services\LmsEnrollmentService;
use App\Services\LmsSessionTransitionService;

/**
 * Session-specific abilities. Ownership derives from the session's own class
 * group via ClassGroupPolicy (reused, not duplicated) when the session is
 * class-scoped; falls back to direct instructor_id ownership for legacy/
 * unscoped (workshop-only) sessions.
 *
 * SECURITY FIX (this pass): the previous `view()`/`teacherOwnsSession()`
 * implementation returned `true` whenever the acting teacher had **no**
 * linked Instructor profile (`if (! $profile) { return true; }`) — the exact
 * null-Instructor authorization bypass Ticket 1 fixed elsewhere in
 * ClassGroupPolicy/InstructorClassController. A `role: instructor` account
 * with no Instructor row could therefore view or mark attendance on *any*
 * session. This is closed below: no linked profile now means denied, never
 * a blanket bypass.
 */
class LmsSessionPolicy
{
    private function classGroupPolicy(): ClassGroupPolicy
    {
        return new ClassGroupPolicy();
    }

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    private function ownsSession(User $user, LmsSession $session): bool
    {
        if ($session->class_group_id && $session->classGroup) {
            return $this->classGroupPolicy()->view($user, $session->classGroup);
        }

        // Legacy/unscoped session — ownership requires an explicit, matching
        // Instructor profile. No profile = denied, never a bypass.
        $profile = Instructor::where('user_id', $user->id)->first();

        return $profile && $session->instructor_id && (int) $session->instructor_id === (int) $profile->id;
    }

    public function view(User $user, LmsSession $session): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isTeacher() && $this->ownsSession($user, $session)) {
            return true;
        }

        return $this->studentCanView($user, $session);
    }

    public function updateAttendance(User $user, LmsSession $session): bool
    {
        return $this->recordAttendance($user, $session);
    }

    public function create(User $user, ?LmsSession $session = null): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $session && $session->classGroup && $this->classGroupPolicy()->createSession($user, $session->classGroup);
    }

    public function update(User $user, LmsSession $session): bool
    {
        return $user->isAdmin() || $this->ownsSession($user, $session);
    }

    public function transition(User $user, LmsSession $session): bool
    {
        return $user->isAdmin() || $this->ownsSession($user, $session);
    }

    public function delete(User $user, LmsSession $session): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->ownsSession($user, $session)
            && $session->status === LmsSession::STATUS_SCHEDULED
            && $session->attendances()->count() === 0;
    }

    public function viewMeetingLink(User $user, LmsSession $session): bool
    {
        if ($user->isAdmin() || ($user->isTeacher() && $this->ownsSession($user, $session))) {
            return true;
        }

        return $this->studentCanView($user, $session) && $session->status !== LmsSession::STATUS_CANCELLED;
    }

    public function recordAttendance(User $user, LmsSession $session): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->ownsSession($user, $session)
            && $session->classGroup
            && $session->classGroup->canPerformTeachingActions();
    }

    public function viewAnalytics(User $user, LmsSession $session): bool
    {
        return $user->isAdmin() || $this->ownsSession($user, $session);
    }

    public function allowedTransitions(LmsSession $session): array
    {
        return app(LmsSessionTransitionService::class)->allowedTransitions($session);
    }

    /**
     * A student may view a class-scoped session only if they hold an active
     * membership in that exact class group. Unscoped sessions fall back to
     * course-wide registration, matching pre-existing enrollment behavior.
     */
    private function studentCanView(User $user, LmsSession $session): bool
    {
        if (! $user->isStudent()) {
            return false;
        }

        if ($session->class_group_id !== null) {
            return ClassGroupStudent::where('class_group_id', $session->class_group_id)
                ->where('user_id', $user->id)
                ->whereIn('status', ClassGroupStudent::ACTIVE_STATUSES)
                ->exists();
        }

        $enrollment = app(LmsEnrollmentService::class);
        $courseId = $enrollment->sessionCourseId($session->course_id, $session->workshop_id);

        return $courseId && $enrollment->isEnrolledInCourse($user, $courseId);
    }
}
