<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

class ReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin()
            || $user->hasPermission(\App\Support\Permissions::MANAGE_REPORTS)
            || $user->hasPermission(\App\Support\Permissions::VIEW_KPI);
    }

    public function view(User $user, Report $report): bool
    {
        if ($user->isAdmin() || $user->hasPermission(\App\Support\Permissions::MANAGE_REPORTS)) {
            return true;
        }

        return $user->hasPermission(\App\Support\Permissions::VIEW_KPI);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission(\App\Support\Permissions::MANAGE_REPORTS);
    }

    public function update(User $user, Report $report): bool
    {
        return $user->isAdmin() || $user->hasPermission(\App\Support\Permissions::MANAGE_REPORTS);
    }

    public function delete(User $user, Report $report): bool
    {
        return $user->isAdmin();
    }
}
