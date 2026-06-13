<?php

namespace App\Policies;

use App\Models\AssignmentSubmission;
use App\Models\User;
use App\Services\InstructorSubmissionScopeService;

class AssignmentSubmissionPolicy
{
    public function __construct(
        private readonly InstructorSubmissionScopeService $scope,
    ) {}

    public function review(User $user, AssignmentSubmission $submission): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        if (! $user->isTeacher()) {
            return false;
        }

        $profile = $user->instructorProfile;
        if (! $profile) {
            return false;
        }

        return $this->scope->instructorOwnsSubmission($profile, $submission);
    }
}
