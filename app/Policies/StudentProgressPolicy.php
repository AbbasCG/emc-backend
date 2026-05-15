<?php

namespace App\Policies;

use App\Models\StudentProgress;
use App\Models\User;

class StudentProgressPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, StudentProgress $progress): bool
    {
        return $user->isAdmin() || (int) $progress->user_id === (int) $user->id;
    }
}
