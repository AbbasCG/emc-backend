<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two permissions of ticket completion review, as rows of `permissions` (so they appear in «الأدوار والصلاحيات» under
 * the group «التذاكر» with their Arabic label and description, and can be granted to a role or to one person):
 *
 *   tickets.review_completion    مراجعة واعتماد إنجاز التذاكر        (in Permissions::all(): super_admin / tech_admin hold it)
 *   tickets.review_participated  اعتماد التذاكر التي أشارك فيها      (NOT in Permissions::all(): explicit grant only)
 *
 * THIS MIGRATION GRANTS NOTHING TO ANY ROLE OR PERSON. Until TicketReviewPermissionSeeder is run (or someone grants the keys
 * in the role editor), only super_admin and tech_admin can review tickets (fail closed).
 *
 * It also adds the two permissions to the per-person delegation tree (`permission_nodes`) WHEN that tree already exists,
 * because the tree is only built lazily while the table is empty; an existing tree would otherwise never list them.
 *
 * Idempotent (safe to re-run); down() removes exactly what up() adds (the grants made later through them go with the rows).
 */
return new class extends Migration
{
    /** @return array<int, array{name: string, display_name: string, label_ar: string, description: string, description_ar: string}> */
    private function definitions(): array
    {
        return [
            [
                'name' => 'tickets.review_completion',
                'display_name' => 'Review and approve ticket completion',
                'label_ar' => 'مراجعة واعتماد إنجاز التذاكر',
                'description' => 'Approve completion, approve for deployment and return for rework on tickets of the departments the holder leads (or all, for a global role). Not enough on its own for tickets the holder created, is assigned to, or submitted.',
                'description_ar' => 'اعتماد إنجاز التذاكر أو اعتمادها للنشر أو إعادتها للتنفيذ ضمن الإدارات التي يقودها حاملها (أو جميع الإدارات لصاحب دور عام). لا تكفي وحدها لاعتماد تذكرة أنشأها أو أُسندت إليه أو أرسل إنجازها؛ لذلك تلزمه صلاحية «اعتماد التذاكر التي أشارك فيها».',
            ],
            [
                'name' => 'tickets.review_participated',
                'display_name' => 'Approve tickets I take part in',
                'label_ar' => 'اعتماد التذاكر التي أشارك فيها',
                'description' => 'Review a ticket the holder created, is assigned to, or submitted the completion of. Needs tickets.review_completion as well. Never granted by role defaults; grant it only on purpose.',
                'description_ar' => 'تتيح اعتماد تذكرة أنشأها صاحبها أو أُسندت إليه أو أرسل إنجازها للمراجعة، بشرط امتلاكه أيضًا «مراجعة واعتماد إنجاز التذاكر». لا يمنحها أي دور تلقائيًا (حتى المدير العام)، وتُمنح عمدًا فقط.',
            ],
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
                'group' => 'tickets',
                'description' => $def['description'],
                'updated_at' => $now,
            ];
            if ($hasLabels) {
                $row += ['label_ar' => $def['label_ar'], 'description_ar' => $def['description_ar']];
            }

            if (DB::table('permissions')->where('name', $def['name'])->exists()) {
                DB::table('permissions')->where('name', $def['name'])->update($row);
            } else {
                DB::table('permissions')->insert($row + ['name' => $def['name'], 'created_at' => $now]);
            }
        }

        $this->addDelegationNodes($now);
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_nodes')) {
            DB::table('permission_nodes')->whereIn('key', array_column($this->definitions(), 'name'))->delete();
            $section = DB::table('permission_nodes')->where('key', 'section.tickets')->first();
            if ($section && ! DB::table('permission_nodes')->where('parent_id', $section->id)->exists()) {
                DB::table('permission_nodes')->where('id', $section->id)->delete();
            }
        }

        if (Schema::hasTable('permissions')) {
            // permission_role, user_permission_assignments and delegatable_permissions rows cascade with the permission.
            DB::table('permissions')->whereIn('name', array_column($this->definitions(), 'name'))->delete();
        }
    }

    /** Only when the tree exists already: an empty table is filled completely (these rows included) by PermissionNodeSeeder on first use. */
    private function addDelegationNodes($now): void
    {
        if (! Schema::hasTable('permission_nodes') || ! DB::table('permission_nodes')->exists()) {
            return;
        }

        $order = (int) DB::table('permission_nodes')->max('sort_order');

        $section = DB::table('permission_nodes')->where('key', 'section.tickets')->first();
        if (! $section) {
            $id = DB::table('permission_nodes')->insertGetId([
                'parent_id' => null, 'node_type' => 'section', 'key' => 'section.tickets', 'slug' => 'section-tickets',
                'label_ar' => 'الدعم الفني والتذاكر', 'label_en' => 'Support & Tickets', 'permission_name' => null,
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
