<?php

namespace App\Policies;

use App\Models\ClassGroup;
use App\Models\Instructor;
use App\Models\User;

/**
 * Single centralized authorization surface for ClassGroup. Business-state
 * rules (which statuses permit which actions) live on the model itself
 * (ClassGroup::canBeEdited()/canPerformTeachingActions()) — this Policy only
 * decides ownership, never re-derives status logic.
 */
class ClassGroupPolicy
{
    /**
     * Deliberately does NOT grant a blanket bypass to `role: admin`. Only
     * super_admin/tech_admin bypass via the global `Gate::before` in
     * AppServiceProvider — that is the correct, existing, tested boundary.
     * A plain admin account with no linked Instructor profile must be
     * denied here exactly like any other instructor-route caller: this was
     * a real vulnerability fixed earlier (an admin without an Instructor
     * profile could otherwise mutate any instructor's class) and must not
     * be silently reintroduced by this Policy.
     */
    private function ownsGroup(User $user, ClassGroup $group): bool
    {
        $profile = Instructor::where('user_id', $user->id)->first();
        if (! $profile) {
            return false;
        }

        return (int) $group->instructor_id === $profile->id;
    }

    public function view(User $user, ClassGroup $group): bool
    {
        return $this->ownsGroup($user, $group);
    }

    public function update(User $user, ClassGroup $group): bool
    {
        return $this->ownsGroup($user, $group);
    }

    /** Configuration edits specifically (vs. a bare status transition, which
     *  update() alone covers) — completed/archived classes reject this. */
    public function updateConfiguration(User $user, ClassGroup $group): bool
    {
        return $this->ownsGroup($user, $group) && $group->canBeEdited();
    }

    public function delete(User $user, ClassGroup $group): bool
    {
        return $this->ownsGroup($user, $group) && $group->canBeEdited();
    }

    public function manageStudents(User $user, ClassGroup $group): bool
    {
        return $this->ownsGroup($user, $group) && $group->canBeEdited();
    }

    public function createSession(User $user, ClassGroup $group): bool
    {
        return $this->ownsGroup($user, $group) && $group->canPerformTeachingActions();
    }

    /** Session status transitions (e.g. cancel/complete) remain allowed even
     *  when the class itself is frozen; only content edits are blocked —
     *  callers distinguish that themselves before invoking updateConfiguration. */
    public function updateSession(User $user, ClassGroup $group): bool
    {
        return $this->ownsGroup($user, $group);
    }

    public function recordAttendance(User $user, ClassGroup $group): bool
    {
        return $this->ownsGroup($user, $group) && $group->canPerformTeachingActions();
    }

    public function manageAssignments(User $user, ClassGroup $group): bool
    {
        return $this->ownsGroup($user, $group) && $group->canPerformTeachingActions();
    }

    public function manageMaterials(User $user, ClassGroup $group): bool
    {
        return $this->ownsGroup($user, $group) && $group->canPerformTeachingActions();
    }

    public function manageAnnouncements(User $user, ClassGroup $group): bool
    {
        return $this->ownsGroup($user, $group) && $group->canBeEdited();
    }

    public function viewAnalytics(User $user, ClassGroup $group): bool
    {
        return $this->ownsGroup($user, $group);
    }

    public function export(User $user, ClassGroup $group): bool
    {
        return $this->ownsGroup($user, $group);
    }
}
