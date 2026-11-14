<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * Users Management hub — the three analytics permissions, with their Arabic labels, so they appear in
 * Access Control → الأدوار (group «المستخدمون») and can be granted to a role on purpose.
 *
 *   users.analytics.view    التحليلات
 *   users.activity.view     النشاط
 *   users.acquisition.view  الاستقطاب
 *
 * SCOPE — deliberately small, safe to run on an existing database:
 *   - upserts exactly these three permission rows (idempotent);
 *   - attaches them to super_admin and tech_admin ONLY, with syncWithoutDetaching-style grants;
 *   - touches no other role, no other permission and no user. No role (admin included) receives them by default.
 *
 * Wired into DatabaseSeeder for fresh environments. On an existing/production database run it explicitly:
 *
 *   php artisan db:seed --class=UsersAnalyticsPermissionSeeder
 *   php artisan optimize:clear    # flush the permission cache
 *
 * (The routes are guarded by the permission middleware, under which super_admin / tech_admin pass regardless of rows, so
 *  the API works before this runs; the rows only make the keys visible and grantable in the UI.)
 */
class UsersAnalyticsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'name'           => Permissions::USERS_ANALYTICS_VIEW,
                'display_name'   => 'View Users Analytics',
                'label_ar'       => 'عرض تحليلات المستخدمين',
                'description'    => 'Account totals, growth and the role/status/country mix in the Users hub (aggregates only).',
                'description_ar' => 'إجماليات الحسابات ونموها وتوزيعها حسب الدور والحالة والبلد في تبويب «التحليلات» (أرقام مجمّعة فقط).',
            ],
            [
                'name'           => Permissions::USERS_ACTIVITY_VIEW,
                'display_name'   => 'View Users Activity',
                'label_ar'       => 'عرض نشاط المستخدمين',
                'description'    => 'Login counts, returning users, login times and inactive accounts in the Users hub.',
                'description_ar' => 'تسجيلات الدخول والمستخدمون العائدون وأوقات الدخول والحسابات غير النشطة، ويشمل أسماء آخر من سجّلوا الدخول.',
            ],
            [
                'name'           => Permissions::USERS_ACQUISITION_VIEW,
                'display_name'   => 'View Users Acquisition',
                'label_ar'       => 'عرض استقطاب المستخدمين',
                'description'    => 'Self-reported sources and campaign visits in the Users hub (aggregates only).',
                'description_ar' => 'مصادر وصول الأعضاء كما يعلنونها وزيارات الحملات في تبويب «الاستقطاب» (أرقام مجمّعة فقط).',
            ],
        ];

        foreach ($definitions as $def) {
            Permission::updateOrCreate(['name' => $def['name']], $def + ['group' => 'users']);
        }

        foreach (['super_admin', 'tech_admin'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                $this->command?->warn("{$roleName} role not found — run RoleSeeder first.");
                continue;
            }
            foreach ($definitions as $def) {
                $role->grantPermission($def['name']);
            }
        }

        $this->command?->info('✓ users.analytics.view / users.activity.view / users.acquisition.view seeded (super_admin, tech_admin only).');
    }
}
