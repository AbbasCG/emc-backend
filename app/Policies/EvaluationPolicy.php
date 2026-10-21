<?php

namespace App\Policies;

use App\Models\User;

class EvaluationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        // Any account may review a course it is enrolled in (the controller checks the enrollment); learning is not a role.
        return true;
    }
}
