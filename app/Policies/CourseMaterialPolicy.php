<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\Registration;
use App\Models\User;
use App\Services\LearningContentEligibilityService;

class CourseMaterialPolicy
{
    private const ADMIN_ROLES  = ['admin', 'super_admin', 'department_manager'];
    private const UPLOAD_ROLES = ['admin', 'super_admin', 'department_manager', 'teacher', 'instructor'];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CourseMaterial $material): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if ($this->isInstructorOf($user, $material)) {
            return true;
        }

        if (! $material->is_visible) {
            return false;
        }

        return $this->isEnrolledStudent($user, $material);
    }

    public function download(User $user, CourseMaterial $material): bool
    {
        return $this->view($user, $material);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, self::UPLOAD_ROLES, true);
    }

    public function update(User $user, CourseMaterial $material): bool
    {
        return $this->isAdmin($user) || $this->isInstructorOf($user, $material);
    }

    public function delete(User $user, CourseMaterial $material): bool
    {
        return $this->update($user, $material);
    }

    public function restore(User $user, CourseMaterial $material): bool
    {
        return $this->isAdmin($user);
    }

    public function forceDelete(User $user, CourseMaterial $material): bool
    {
        return in_array($user->role, ['admin', 'super_admin'], true);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function isAdmin(User $user): bool
    {
        return in_array($user->role, self::ADMIN_ROLES, true);
    }

    private function isInstructorOf(User $user, CourseMaterial $material): bool
    {
        if (! in_array($user->role, ['teacher', 'instructor'], true)) {
            return false;
        }
        $profile = $user->instructorProfile;
        if (! $profile || ! $material->course_id) {
            return false;
        }
        return Course::where('id', $material->course_id)
            ->where('instructor_id', $profile->id)
            ->exists();
    }

    private function isEnrolledStudent(User $user, CourseMaterial $material): bool
    {
        // Delegates to the canonical eligibility service, which also
        // cross-checks material.session_id/course_session_id against the
        // student's actual class membership — course enrollment and a
        // matching material.class_group_id alone are not enough, since a
        // material can be linked to a session belonging to a different
        // class than the material's own class_group_id declares.
        return app(LearningContentEligibilityService::class)->studentCanViewMaterial($user, $material);
    }
}
