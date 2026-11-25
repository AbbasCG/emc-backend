<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The READ permission of the HR tasks & approvals page (/dashboard/hr/tasks), as a row of `permissions` (so it appears in
 * «الأدوار والصلاحيات» under the group «الموارد البشرية» with its Arabic label and description, and can be granted to a role or to
 * one person under «مركز التحكم بالوصول ← المستخدمون ← صلاحيات الإجراءات»):
 *
 *   hr_tasks.view   عرض مهام واعتمادات الموارد البشرية   (in Permissions::all(): super_admin / tech_admin hold it)
 *
 * THIS MIGRATION GRANTS NOTHING TO ANY ROLE OR PERSON. Page access (hr.tasks) only opens the page; this permission is what lets
 * GET /api/admin/tasks/pending-hr-approval and GET /api/operations/tasks answer it. hr-approve / evaluate / reject / reassign stay
 * role-gated and are not reachable through it.
 *
 * Idempotent (safe to re-run); down() removes exactly what up() adds (grants made later through it go with the row).
 */
return new class extends Migration
{
    private const NAME = 'hr_tasks.view';

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $row = [
            'display_name' => 'View HR tasks and approvals',
            'group' => 'hr',
            'description' => 'Read the tasks awaiting HR approval and the general task log on the HR tasks page. Exposes ALL tasks of every department with the manager evaluation feedback and notes (HR-sensitive): grant deliberately. Does not allow approving, evaluating, rejecting or reassigning a task.',
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('permissions', 'label_ar') && Schema::hasColumn('permissions', 'description_ar')) {
            $row += [
                'label_ar' => 'عرض مهام واعتمادات الموارد البشرية',
                'description_ar' => 'قراءة المهام بانتظار اعتماد الموارد البشرية وسجل المهام العام في صفحة «مهام واعتمادات الموارد البشرية». تنبيه: القراءة تكشف جميع مهام المنصة في كل الإدارات مع تقييم مدير الإدارة وملاحظاته (بيانات حساسة للموارد البشرية)، فامنحها عن قصد وللمسؤولين عن الاعتماد فقط. لا تتيح اعتماد المهمة أو تقييمها أو رفضها أو إعادة إسنادها؛ هذه تبقى لأدوارها. فتح الصفحة وحده لا يكفي لعرض البيانات.',
            ];
        }

        $existing = DB::table('permissions')->where('name', self::NAME)->first();
        if ($existing) {
            // Re-run: fill only what is still empty, never overwrite a label or description an admin edited.
            $fill = ['updated_at' => $now];
            foreach (['display_name', 'description', 'label_ar', 'description_ar'] as $column) {
                if (array_key_exists($column, $row) && trim((string) ($existing->{$column} ?? '')) === '') {
                    $fill[$column] = $row[$column];
                }
            }
            DB::table('permissions')->where('name', self::NAME)->update($fill);
        } else {
            DB::table('permissions')->insert($row + ['name' => self::NAME, 'created_at' => $now]);
        }

        $this->addDelegationNode($now);
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_nodes')) {
            DB::table('permission_nodes')->where('key', self::NAME)->delete();
        }

        if (Schema::hasTable('permissions')) {
            // permission_role, user_permission_assignments and delegatable_permissions rows cascade with the permission.
            DB::table('permissions')->where('name', self::NAME)->delete();
        }
    }

    /** Only when the delegation tree exists already (an empty table is filled completely by PermissionNodeSeeder on first use). */
    private function addDelegationNode($now): void
    {
        if (! Schema::hasTable('permission_nodes') || ! DB::table('permission_nodes')->exists()
            || DB::table('permission_nodes')->where('key', self::NAME)->exists()) {
            return;
        }

        $section = DB::table('permission_nodes')->where('key', 'section.hr')->first();
        if (! $section) {
            return; // no HR section in this tree: PermissionNodeSeeder places the node when it next runs
        }

        DB::table('permission_nodes')->insert([
            'parent_id' => $section->id, 'node_type' => 'action', 'key' => self::NAME, 'slug' => 'hr-tasks-view',
            'label_ar' => 'عرض مهام واعتمادات الموارد البشرية', 'label_en' => 'View HR tasks and approvals',
            'description_ar' => 'قراءة المهام بانتظار اعتماد الموارد البشرية وسجل المهام العام.', 'permission_name' => self::NAME,
            'sort_order' => (int) DB::table('permission_nodes')->max('sort_order') + 1, 'is_active' => true, 'is_system' => false,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
};
