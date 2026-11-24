<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The READ permission of the consultant applications, as a row of `permissions` (so it appears in «الأدوار والصلاحيات» under the
 * group «الموارد البشرية» with its Arabic label and description, and can be granted to a role or to one person under
 * «مركز التحكم بالوصول ← المستخدمون ← صلاحيات الإجراءات»):
 *
 *   consultant_applications.view   عرض طلبات الاستشاريين   (in Permissions::all(): super_admin / tech_admin hold it)
 *
 * THIS MIGRATION GRANTS NOTHING TO ANY ROLE OR PERSON. Page access (organizational_departments.consultant_applications) only opens the
 * page; this permission is what lets GET /api/admin/consultant-applications[/{id}] answer it. Approve / reject stays admin-tier only.
 *
 * Idempotent (safe to re-run); down() removes exactly what up() adds (grants made later through it go with the row).
 */
return new class extends Migration
{
    private const NAME = 'consultant_applications.view';

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $row = [
            'display_name' => 'View consultant applications',
            'group' => 'hr',
            'description' => 'Read the consultant applications list and details (including the CV preview link). Does not allow approving, rejecting or editing an application.',
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('permissions', 'label_ar') && Schema::hasColumn('permissions', 'description_ar')) {
            $row += [
                'label_ar' => 'عرض طلبات الاستشاريين',
                'description_ar' => 'قراءة قائمة طلبات الاستشاريين وتفاصيل كل طلب (مع رابط معاينة السيرة الذاتية). لا تتيح اعتماد الطلب أو رفضه أو تعديل حالته؛ هذه تبقى للإدارة العليا. فتح الصفحة وحده لا يكفي لعرض البيانات.',
            ];
        }

        if (DB::table('permissions')->where('name', self::NAME)->exists()) {
            DB::table('permissions')->where('name', self::NAME)->update($row);
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
            'parent_id' => $section->id, 'node_type' => 'action', 'key' => self::NAME, 'slug' => 'consultant-applications-view',
            'label_ar' => 'عرض طلبات الاستشاريين', 'label_en' => 'View consultant applications',
            'description_ar' => 'قراءة قائمة طلبات الاستشاريين وتفاصيلها.', 'permission_name' => self::NAME,
            'sort_order' => (int) DB::table('permission_nodes')->max('sort_order') + 1, 'is_active' => true, 'is_system' => false,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
};
