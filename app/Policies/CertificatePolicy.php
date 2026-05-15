<?php

namespace App\Policies;

use App\Models\Certificate;
use App\Models\User;

class CertificatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStudent();
    }

    public function view(User $user, Certificate $certificate): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $certificate->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function approve(User $user, Certificate $certificate): bool
    {
        return $user->isAdmin();
    }

    public function issue(User $user, Certificate $certificate): bool
    {
        return $user->isAdmin();
    }

    public function revoke(User $user, Certificate $certificate): bool
    {
        return $user->isAdmin();
    }
}
