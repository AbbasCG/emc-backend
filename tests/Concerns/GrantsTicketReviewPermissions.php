<?php

namespace Tests\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionAssignment;
use App\Support\Permissions;

/**
 * Test helpers for the two ticket-review permissions (tickets.review_completion / tickets.review_participated).
 *
 * The permission rows come from the migration (2026_11_26_000001), so they exist after RefreshDatabase. Grants go through the
 * very mechanism production uses: a per-user assignment (allow / deny) or a role row.
 */
trait GrantsTicketReviewPermissions
{
    /** Give $user «مراجعة واعتماد إنجاز التذاكر» (and, on request, «اعتماد التذاكر التي أشارك فيها») as a per-user allow. */
    protected function grantTicketReview(User $user, bool $participated = false): User
    {
        $this->assignPermission($user, Permissions::TICKETS_REVIEW_COMPLETION, 'allow');
        if ($participated) {
            $this->assignPermission($user, Permissions::TICKETS_REVIEW_PARTICIPATED, 'allow');
        }

        return $user;
    }

    /**
     * The roles that review today (leaders' roles and the global roles), as TicketReviewPermissionSeeder grants them. A test's user()
     * factory calls this so a fixture "admin" / "programs_manager" holds tickets.review_completion like production does after the
     * seeder; the participation permission is never part of it.
     */
    protected function grantReviewToReviewerRole(User $user): User
    {
        if (in_array($user->role, ['super_admin', 'tech_admin', 'admin', 'executive_admin', 'hr_manager', 'programs_manager', 'department_manager'], true)) {
            $this->grantTicketReview($user);
        }

        return $user;
    }

    protected function assignPermission(User $user, string $permission, string $state = 'allow'): void
    {
        UserPermissionAssignment::query()->updateOrCreate(
            ['user_id' => $user->id, 'permission_id' => Permission::query()->where('name', $permission)->firstOrFail()->id],
            ['state' => $state, 'scope_type' => 'global', 'source_type' => 'direct_admin'],
        );
        $user->flushPermissionCache();
    }

    /** Grant a permission to a ROLE row (the role editor path). Creates the role row when the test database has none. */
    protected function grantToRole(string $roleName, string $permission): void
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName], ['display_name' => $roleName, 'is_system' => false]);
        $role->grantPermission($permission);
    }
}
