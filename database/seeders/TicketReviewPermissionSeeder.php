<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionAssignment;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * OPT-IN seeder: keeps today's ticket reviewers reviewing after tickets.review_completion became a permission.
 *
 * NOT wired into DatabaseSeeder::run(). On an existing/production database run it explicitly, ONCE, in this order:
 *
 *   php artisan migrate            # FIRST, and before the new code serves requests: it creates the permission rows and the
 *                                  # ticket_activity_logs.metadata column the review endpoints now write
 *   php artisan db:seed --class=TicketReviewPermissionSeeder
 *   php artisan optimize:clear     # flush the permission cache
 *
 * WHAT IT GRANTS tickets.review_completion («مراجعة واعتماد إنجاز التذاكر») TO — exactly the people who could review before, nothing wider:
 *   1. the global roles that pass TicketAccess::canManage on every department: super_admin, tech_admin, executive_admin, admin
 *      (role-wide grants; super_admin / tech_admin hold the key through Permissions::all() anyway, the row only makes it visible
 *      in the role editor);
 *   2. EVERY active user who currently leads a department (departments.leader_id) and is not covered by 1: a PER-USER allow. Never a
 *      role-wide grant, so a role such as department_manager never hands the key to people who do not lead anything. The department
 *      scope (TicketAccess::canManage) is still judged per ticket, so the permission never widens WHERE someone can review.
 * A person who has an explicit per-user DENY is skipped (the deny stays in force).
 *
 * WHAT IT NEVER GRANTS: tickets.review_participated («اعتماد التذاكر التي أشارك فيها»), to anyone, ever. Granting that one is a
 * conscious decision of an actual super_admin (role editor / per-user permissions).
 *
 * WHAT HAPPENS IF IT IS NOT RUN: fail closed. Only super_admin and tech_admin (who hold the key implicitly) can approve, approve
 * for deployment or return a ticket; leaders and admin / executive_admin get 403 REVIEW_PERMISSION_REQUIRED until granted.
 *
 * Idempotent: syncWithoutDetaching for roles, updateOrCreate per user. Re-run it whenever a new department leader is appointed, or
 * grant the key by hand.
 *
 * WARNING: RolePermissionSeeder SYNCS (replaces) every role's permissions from its static map. Re-running it on a live database
 * WIPES every explicit role grant of both ticket permissions (including a super_admin grant of tickets.review_participated).
 * Re-run this seeder afterwards and re-grant the participation key on purpose. Per-user allows survive it.
 */
class TicketReviewPermissionSeeder extends Seeder
{
    /** Mirrors DepartmentAccessService::GLOBAL_ROLES (a unit test keeps the two in step). */
    public const GLOBAL_ROLES = ['super_admin', 'tech_admin', 'executive_admin', 'admin'];

    /**
     * What run() will grant, without writing anything.
     *
     * @return array{roles: array<int, string>, users: array<int, int>, skipped_denied: array<int, int>}
     */
    public function plan(): array
    {
        $users = User::query()
            ->where('is_active', true)
            ->whereNotIn('role', self::GLOBAL_ROLES)
            ->whereIn('id', Department::query()->whereNotNull('leader_id')->select('leader_id'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $permission = Permission::query()->where('name', Permissions::TICKETS_REVIEW_COMPLETION)->first();
        $denied = $permission
            ? UserPermissionAssignment::query()->where('permission_id', $permission->id)->where('state', 'deny')->pluck('user_id')->map(fn ($id) => (int) $id)->all()
            : [];

        return [
            'roles' => self::GLOBAL_ROLES,
            'users' => array_values(array_diff($users, $denied)),
            'skipped_denied' => array_values(array_intersect($users, $denied)),
        ];
    }

    public function run(): void
    {
        // The migration creates the rows; this keeps the seeder safe on a database where it has not run yet.
        $permission = Permission::query()->firstOrCreate(
            ['name' => Permissions::TICKETS_REVIEW_COMPLETION],
            ['display_name' => 'Review and approve ticket completion', 'label_ar' => 'مراجعة واعتماد إنجاز التذاكر', 'group' => 'tickets'],
        );

        $plan = $this->plan();
        $granted = [];
        foreach ($plan['roles'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if (! $role) {
                $this->command?->warn("Role [{$roleName}] does not exist in the roles table; skipped (grant the key to its people by hand).");

                continue;
            }
            $role->grantPermission($permission);
            $granted[] = $roleName;
        }

        foreach ($plan['users'] as $userId) {
            UserPermissionAssignment::query()->updateOrCreate(
                ['user_id' => $userId, 'permission_id' => $permission->id],
                ['state' => 'allow', 'scope_type' => 'global', 'source_type' => 'direct_admin', 'reason' => 'TicketReviewPermissionSeeder: leads a department'],
            );
        }

        $this->command?->info('✓ tickets.review_completion granted to roles: '.($granted ? implode(', ', $granted) : '(none)').'; per-user allows: '.count($plan['users']).'.');
        if ($plan['skipped_denied']) {
            $this->command?->warn('  Skipped (explicit per-user deny): user ids '.implode(', ', $plan['skipped_denied']).'.');
        }
        $this->command?->info('  tickets.review_participated was NOT granted to anyone. Run php artisan optimize:clear to flush the permission cache.');
    }
}
