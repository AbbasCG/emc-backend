<?php

namespace App\Policies;

use App\Models\Certificate;
use App\Models\User;

class CertificatePolicy
{
    private const ADMIN_ROLES = ['admin', 'super_admin', 'tech_admin', 'programs_manager'];

    private function isAdminRole(User $user): bool
    {
        return in_array($user->role, self::ADMIN_ROLES, true);
    }

    // ── List / Read ───────────────────────────────────────────────────────────

    public function viewAny(User $user): bool
    {
        return $this->isAdminRole($user) || $user->isStudent();
    }

    public function view(User $user, Certificate $certificate): bool
    {
        if ($this->isAdminRole($user)) {
            return true;
        }

        // A certificate is its owner's, whatever role the owner holds.
        return $certificate->user_id === $user->id;
    }

    // ── Create / Generate ─────────────────────────────────────────────────────

    public function create(User $user): bool
    {
        return $this->isAdminRole($user);
    }

    public function regenerate(User $user, Certificate $certificate): bool
    {
        return $this->isAdminRole($user);
    }

    // ── Download ──────────────────────────────────────────────────────────────

    public function download(User $user, Certificate $certificate): bool
    {
        if ($this->isAdminRole($user)) {
            return true;
        }

        // A learner may only download their own issued certificates, whatever role they hold
        return $certificate->user_id === $user->id
            && $certificate->status === 'issued';
    }

    // ── Approve / Issue ───────────────────────────────────────────────────────

    public function approve(User $user, Certificate $certificate): bool
    {
        return $this->isAdminRole($user);
    }

    public function issue(User $user, Certificate $certificate): bool
    {
        return $this->isAdminRole($user);
    }

    // ── Revoke ────────────────────────────────────────────────────────────────

    public function revoke(User $user, Certificate $certificate): bool
    {
        // Only admin/super_admin/tech_admin can revoke — programs_manager cannot
        return in_array($user->role, ['admin', 'super_admin', 'tech_admin'], true);
    }

    // ── Templates ─────────────────────────────────────────────────────────────

    public function manageTemplates(User $user): bool
    {
        return in_array($user->role, ['admin', 'super_admin', 'tech_admin'], true);
    }

    // ── Batches ───────────────────────────────────────────────────────────────

    public function manageBatches(User $user): bool
    {
        return $this->isAdminRole($user);
    }

    // ── Approval record ───────────────────────────────────────────────────────

    public function recordApproval(User $user, Certificate $certificate): bool
    {
        return $this->isAdminRole($user);
    }
}
