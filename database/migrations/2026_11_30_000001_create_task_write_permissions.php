<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The three WRITE permissions of operational tasks (the operations board and the task screens), as rows of `permissions`
 * (group `tasks`, shown as «المهام والعمليات» in «الأدوار والصلاحيات» and in «مركز التحكم بالوصول ← المستخدمون ← صلاحيات الإجراءات»):
 *
 *   tasks.create   إنشاء مهمة تشغيلية
 *   tasks.assign   إسناد المهام وإعادة إسنادها
 *   tasks.update   تعديل المهام القائمة
 *
 * Independent keys (create + assign never implies update). All three are in Permissions::all(): super_admin / tech_admin hold them.
 *
 * GRANTS. Until now the routes were role lists, so the roles that could do each thing get exactly that through their EXISTING role
 * row (mirrors Permissions::forRole(), and nothing wider):
 *
 *   admin               create + assign + update   (POST admin/tasks, PATCH/PUT admin/tasks/{task}, .../reassign were role:admin,...)
 *   operations_manager  create + assign + update   (same role list)
 *   executive_admin     create                     (only POST operations/board/tasks was ever open to it)
 *   department_manager  create                     (same; now limited to the departments it leads)
 *
 * hr_manager and every other role get NOTHING here: the owner grants it from the UI. A role without a row in `roles` is not
 * touched (Permissions::forRole() is its fallback and already carries the same defaults). Nothing is revoked or overwritten.
 *
 * Idempotent (safe to re-run); down() removes the permission rows (their role grants and per-user assignments cascade).
 */
return new class extends Migration
{
    private const GROUP = 'tasks';

    /** @return array<int, array{name: string, display_name: string, label_ar: string, description: string, description_ar: string}> */
    private function definitions(): array
    {
        return [
            [
                'name' => 'tasks.create',
                'display_name' => 'Create operational tasks',
                'label_ar' => 'إنشاء مهمة تشغيلية',
                'description' => 'Create a task on the operations board. A holder who is not a global role may only create tasks in the departments they lead. Creating a task WITH an assignee also needs tasks.assign. Does not allow editing or reassigning an existing task.',
                'description_ar' => 'إنشاء مهمة تشغيلية جديدة من لوحة العمليات. من لا يملك دورًا عامًا يُنشئ المهام في الإدارات التي يقودها فقط. إنشاء مهمة مع تحديد المكلَّف يتطلب أيضًا «إسناد المهام وإعادة إسنادها». لا تتيح تعديل مهمة قائمة ولا إعادة إسنادها.',
            ],
            [
                'name' => 'tasks.assign',
                'display_name' => 'Assign and reassign tasks',
                'label_ar' => 'إسناد المهام وإعادة إسنادها',
                'description' => 'Put a task on a member (when creating it) and reassign an existing task. A holder who is not a global role may only assign to members of the departments they lead, on tasks of those departments. Does not allow creating or editing a task.',
                'description_ar' => 'إسناد المهمة إلى عضو (عند إنشائها) وإعادة إسناد مهمة قائمة. من لا يملك دورًا عامًا يُسند لأعضاء الإدارات التي يقودها فقط وعلى مهام تلك الإدارات. لا تتيح إنشاء مهمة ولا تعديل بياناتها.',
            ],
            [
                'name' => 'tasks.update',
                'display_name' => 'Edit existing tasks',
                'label_ar' => 'تعديل المهام القائمة',
                'description' => 'Edit the data of an existing, unlocked task (title, description, priority, size, points, dates, department). A holder who is not a global role may only edit tasks of the departments they lead. Changing the assignee also needs tasks.assign. Does not allow creating, evaluating, approving, locking or deleting a task.',
                'description_ar' => 'تعديل بيانات مهمة قائمة وغير مقفلة (العنوان والوصف والأولوية والحجم والنقاط والمواعيد والإدارة). من لا يملك دورًا عامًا يعدّل مهام الإدارات التي يقودها فقط. تغيير المكلَّف يتطلب أيضًا «إسناد المهام وإعادة إسنادها». لا تتيح إنشاء مهمة ولا تقييمها ولا اعتمادها ولا قفلها ولا حذفها.',
            ],
        ];
    }

    /** @return array<string, array<int, string>> role name => permissions its existing row receives (what the role could already do) */
    private function grants(): array
    {
        return [
            'admin' => ['tasks.create', 'tasks.assign', 'tasks.update'],
            'operations_manager' => ['tasks.create', 'tasks.assign', 'tasks.update'],
            'executive_admin' => ['tasks.create'],
            'department_manager' => ['tasks.create'],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $hasLabels = Schema::hasColumn('permissions', 'label_ar') && Schema::hasColumn('permissions', 'description_ar');

        foreach ($this->definitions() as $def) {
            $row = [
                'display_name' => $def['display_name'],
                'group' => self::GROUP,
                'description' => $def['description'],
                'updated_at' => $now,
            ];
            if ($hasLabels) {
                $row += ['label_ar' => $def['label_ar'], 'description_ar' => $def['description_ar']];
            }

            $existing = DB::table('permissions')->where('name', $def['name'])->first();
            if ($existing) {
                // Re-run: fill only what is still empty, never overwrite a label or description an admin edited.
                $fill = ['updated_at' => $now];
                foreach (['display_name', 'group', 'description', 'label_ar', 'description_ar'] as $column) {
                    if (array_key_exists($column, $row) && trim((string) ($existing->{$column} ?? '')) === '') {
                        $fill[$column] = $row[$column];
                    }
                }
                DB::table('permissions')->where('name', $def['name'])->update($fill);
            } else {
                DB::table('permissions')->insert($row + ['name' => $def['name'], 'created_at' => $now]);
            }
        }

        $this->grantToExistingRoles();
        $this->addDelegationNodes($now);
    }

    public function down(): void
    {
        $names = array_column($this->definitions(), 'name');

        if (Schema::hasTable('permission_nodes')) {
            DB::table('permission_nodes')->whereIn('key', $names)->delete();
            $section = DB::table('permission_nodes')->where('key', 'section.'.self::GROUP)->first();
            if ($section && ! DB::table('permission_nodes')->where('parent_id', $section->id)->exists()) {
                DB::table('permission_nodes')->where('id', $section->id)->delete();
            }
        }

        if (Schema::hasTable('permissions')) {
            // permission_role, user_permission_assignments and delegatable_permissions rows cascade with the permission.
            DB::table('permissions')->whereIn('name', $names)->delete();
        }
    }

    /** Additive: a grant already there is left alone, a role with no row is skipped, no other role is touched. */
    private function grantToExistingRoles(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $permissionIds = DB::table('permissions')->whereIn('name', array_column($this->definitions(), 'name'))->pluck('id', 'name');

        foreach ($this->grants() as $roleName => $names) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if ($roleId === null) {
                continue;
            }
            foreach ($names as $name) {
                $permissionId = $permissionIds[$name] ?? null;
                if ($permissionId === null) {
                    continue;
                }
                $has = DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists();
                if (! $has) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        }
    }

    /** Only when the tree exists already: an empty table is filled completely (these rows included) by PermissionNodeSeeder on first use. */
    private function addDelegationNodes($now): void
    {
        if (! Schema::hasTable('permission_nodes') || ! DB::table('permission_nodes')->exists()) {
            return;
        }

        $order = (int) DB::table('permission_nodes')->max('sort_order');

        $section = DB::table('permission_nodes')->where('key', 'section.'.self::GROUP)->first();
        if (! $section) {
            $id = DB::table('permission_nodes')->insertGetId([
                'parent_id' => null, 'node_type' => 'section', 'key' => 'section.'.self::GROUP, 'slug' => 'section-'.self::GROUP,
                'label_ar' => 'المهام والعمليات', 'label_en' => 'Tasks & Operations', 'permission_name' => null,
                'sort_order' => ++$order, 'is_active' => true, 'is_system' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            $id = $section->id;
        }

        foreach ($this->definitions() as $def) {
            if (DB::table('permission_nodes')->where('key', $def['name'])->exists()) {
                continue;
            }
            DB::table('permission_nodes')->insert([
                'parent_id' => $id, 'node_type' => 'action', 'key' => $def['name'], 'slug' => str_replace(['.', '_'], '-', $def['name']),
                'label_ar' => $def['label_ar'], 'label_en' => $def['display_name'], 'description_ar' => $def['description_ar'],
                'permission_name' => $def['name'], 'sort_order' => ++$order, 'is_active' => true, 'is_system' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
};
