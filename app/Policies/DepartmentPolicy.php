<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    public function before(User $actor, string $ability): ?bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('view_departments');
    }

    public function view(User $actor, Department $department): bool
    {
        if ($actor->hasPermission('view_departments')) {
            return true;
        }

        // Department managers can view their own department
        if ($actor->role === 'department_manager') {
            return $this->managesThisDepartment($actor, $department);
        }

        return false;
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('manage_departments');
    }

    public function update(User $actor, Department $department): bool
    {
        if ($actor->hasPermission('manage_departments')) {
            return true;
        }

        return $actor->role === 'department_manager' && $this->managesThisDepartment($actor, $department);
    }

    public function delete(User $actor, Department $department): bool
    {
        return $actor->hasPermission('manage_departments');
    }

    private function managesThisDepartment(User $actor, Department $department): bool
    {
        // Check if the user is listed as manager of this department
        if (method_exists($department, 'manager_id')) {
            return (int) $department->manager_id === $actor->id;
        }

        // Fallback: check TeamMember table if it exists
        try {
            return $actor->teamMemberships()
                ->where('department_id', $department->id)
                ->where('role', 'manager')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
