<?php

namespace App\Policies;

use App\Models\LearningPath;
use App\Models\User;

class LearningPathPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin', 'tech_admin', 'programs_manager']);
    }

    public function view(User $user, LearningPath $learningPath): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin', 'tech_admin', 'programs_manager']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin', 'tech_admin', 'programs_manager']);
    }

    public function update(User $user, LearningPath $learningPath): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin', 'tech_admin', 'programs_manager']);
    }

    public function delete(User $user, LearningPath $learningPath): bool
    {
        // Only admins may permanently delete; programs_manager may archive but not delete.
        return $user->hasAnyRole(['admin', 'super_admin', 'tech_admin']);
    }

    public function archive(User $user, LearningPath $learningPath): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin', 'tech_admin', 'programs_manager']);
    }
}
