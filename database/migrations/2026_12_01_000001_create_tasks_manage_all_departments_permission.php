<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The SCOPE permission of operational tasks, as a row of `permissions` (group `tasks`, shown as «المهام والعمليات» in
 * «الأدوار والصلاحيات» and in «مركز التحكم بالوصول ← المستخدمون ← صلاحيات الإجراءات»):
 *
 *   tasks.manage_all_departments   إدارة مهام كل الأقسام
 *
 * It widens the DEPARTMENTS a holder may write tasks in (from the ones they lead to every department) and nothing else: tasks.create /
 * tasks.assign / tasks.update stay required for each action, a per-user deny of any of them still closes it, and the credit-bearing
 * guards, the lock ownership and the transition rules keep binding the holder. It implies neither evaluating nor deleting.
 * super_admin / tech_admin hold it through Permissions::all() (they are global anyway).
 *
 * THIS MIGRATION GRANTS NOTHING TO ANY ROLE OR PERSON (no hr_manager grant, no existing account): the owner grants it to one account
 * at a time from the UI, or to a role row deliberately. Until then every account behaves exactly as before.
 *
 * Idempotent (safe to re-run); down() removes exactly what up() adds (grants made later through it go with the row).
 */
return new class extends Migration
{
    private const NAME = 'tasks.manage_all_departments';

    private const GROUP = 'tasks';

    private const LABEL_AR = 'إدارة مهام كل الأقسام';

    private const DISPLAY = 'Manage tasks of every department';

    private const DESCRIPTION = 'Widen the departments a holder may write tasks in from the ones they lead to EVERY department (tasks and people alike). It writes nothing by itself: creating, assigning and editing still need tasks.create, tasks.assign and tasks.update, and a per-user deny of any of them still closes that action. The holder stays bound by the guards of a delegated actor: work awaiting approval, approved or completed is never edited, reassigned, moved or locked; a lock placed by somebody else stays theirs; a task of no department stays a global actor\'s. It does not allow evaluating, approving or deleting a task.';

    private const DESCRIPTION_AR = 'توسيع الإدارات التي يعمل فيها صاحب الصلاحية على المهام من الإدارات التي يقودها إلى كل الأقسام (المهام والأعضاء معًا). لا تكتب شيئًا بذاتها: الإنشاء والإسناد والتعديل تبقى بحاجة إلى «إنشاء مهمة تشغيلية» و«إسناد المهام وإعادة إسنادها» و«تعديل المهام القائمة»، وأي منع فردي لإحداها يغلق إجراءها. يظل صاحبها مقيَّدًا بحواجز الدور المفوَّض: لا يعدّل ولا يعيد إسناد ولا يحرّك ولا يقفل مهمة بانتظار الاعتماد أو معتمدة أو مكتملة، ولا يمسّ قفلًا وضعه غيره، والمهمة بلا إدارة تبقى للأدوار العامة. لا تتيح تقييم مهمة ولا اعتمادها ولا حذفها. تُمنح لحساب بعينه عن قصد.';

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $row = [
            'display_name' => self::DISPLAY,
            'group' => self::GROUP,
            'description' => self::DESCRIPTION,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('permissions', 'label_ar') && Schema::hasColumn('permissions', 'description_ar')) {
            $row += ['label_ar' => self::LABEL_AR, 'description_ar' => self::DESCRIPTION_AR];
        }

        $existing = DB::table('permissions')->where('name', self::NAME)->first();
        if ($existing) {
            // Re-run: fill only what is still empty, never overwrite a label or description an admin edited.
            $fill = ['updated_at' => $now];
            foreach (['display_name', 'group', 'description', 'label_ar', 'description_ar'] as $column) {
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

        $section = DB::table('permission_nodes')->where('key', 'section.'.self::GROUP)->first();
        if (! $section) {
            return; // no tasks section in this tree: PermissionNodeSeeder places the node when it next runs
        }

        DB::table('permission_nodes')->insert([
            'parent_id' => $section->id, 'node_type' => 'action', 'key' => self::NAME, 'slug' => 'tasks-manage-all-departments',
            'label_ar' => self::LABEL_AR, 'label_en' => self::DISPLAY, 'description_ar' => self::DESCRIPTION_AR,
            'permission_name' => self::NAME, 'sort_order' => (int) DB::table('permission_nodes')->max('sort_order') + 1,
            'is_active' => true, 'is_system' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
};
