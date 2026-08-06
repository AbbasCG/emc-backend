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
        return $user->isStudent() || $user->isAdmin();
    }
}
