<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\TeamProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The duplicate-department bug as users see it: the same department twice on
 * /ar/team and in dashboard dropdowns, plus the guards that stop a new one
 * being created.
 *
 * Root cause covered here: merging archives the duplicate via `status`, but the
 * public team endpoint and the department selectors only checked `is_active`,
 * so the archived row kept being served.
 */
class DepartmentDuplicateApiTest extends TestCase
{
    use RefreshDatabase;

    private const AI = 'إدارة الذكاء الاصطناعي والتحول الرقمي';

    private function dept(string $slug, array $over = []): Department
    {
        return Department::create(array_merge([
            'name' => self::AI, 'name_ar' => self::AI, 'name_en' => 'AI & Digital Transformation',
            'slug' => $slug, 'status' => 'active', 'is_active' => true, 'sort_order' => 1,
        ], $over));
    }

    private function user(string $role = 'student'): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /** The exact leaking shape: archived by a merge, is_active still true. */
    private function archivedDuplicate(): Department
    {
        return $this->dept('ai-digital-transformation', ['status' => 'archived', 'is_active' => true, 'sort_order' => 2]);
    }

    // ── /ar/team ────────────────────────────────────────────────────────────

    public function test_public_team_lists_the_department_once_when_the_duplicate_is_archived(): void
    {
        $canonical = $this->dept('ai-transformation');
        $this->archivedDuplicate();

        $data = $this->getJson('/api/team')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($canonical->id, $data[0]['id']);
    }

    public function test_public_team_groups_all_people_under_the_single_department(): void
    {
        $canonical = $this->dept('ai-transformation');
        $dup = $this->dept('ai-digital-transformation');
        TeamProfile::create(['department_id' => $dup->id, 'name_ar' => 'منقول', 'position_ar' => 'عضو', 'is_active' => true]);
        TeamProfile::create(['department_id' => $canonical->id, 'name_ar' => 'أصلي', 'position_ar' => 'عضو', 'is_active' => true]);

        $this->artisan('departments:merge-duplicates', ['--name' => self::AI, '--apply' => true])->assertSuccessful();

        $data = $this->getJson('/api/team')->assertOk()->json('data');
        $this->assertCount(1, $data, 'one section, not two');
        $this->assertEqualsCanonicalizing(['منقول', 'أصلي'], collect($data[0]['members'])->pluck('name_ar')->all());
    }

    public function test_public_team_still_hides_inactive_departments(): void
    {
        $this->dept('ai-transformation');
        $this->dept('hidden', ['name' => 'قسم مخفي', 'name_ar' => 'قسم مخفي', 'name_en' => 'Hidden', 'is_active' => false]);

        $this->assertCount(1, $this->getJson('/api/team')->assertOk()->json('data'));
    }

    // ── Dropdowns ───────────────────────────────────────────────────────────

    public function test_volunteer_hr_department_dropdown_returns_one_canonical_option(): void
    {
        $canonical = $this->dept('ai-transformation');
        $this->archivedDuplicate();

        $options = $this->actingAs($this->user())->getJson('/api/departments/options')->assertOk()->json('data');

        $this->assertCount(1, $options);
        $this->assertSame($canonical->id, $options[0]['id'], 'the option value must be the canonical department id');
        $this->assertSame(self::AI, $options[0]['name_ar']);
    }

    public function test_dropdown_options_are_ordered_consistently(): void
    {
        $b = $this->dept('b', ['name' => 'ب', 'name_ar' => 'ب', 'name_en' => 'B', 'sort_order' => 5]);
        $a = $this->dept('a', ['name' => 'أ', 'name_ar' => 'أ', 'name_en' => 'A', 'sort_order' => 5]);
        $first = $this->dept('c', ['name' => 'ج', 'name_ar' => 'ج', 'name_en' => 'C', 'sort_order' => 1]);

        $ids = collect($this->actingAs($this->user())->getJson('/api/departments/options')->json('data'))->pluck('id')->all();

        $this->assertSame([$first->id, $b->id, $a->id], $ids, 'sort_order, then id — deterministic even on ties');
    }

    public function test_hr_filter_options_dropdown_excludes_the_archived_duplicate(): void
    {
        $canonical = $this->dept('ai-transformation');
        $this->archivedDuplicate();

        $ids = collect($this->actingAs($this->user('hr_manager'))->getJson('/api/hr/volunteer-hr-profiles/filter-options')->assertOk()->json('data.departments'))->pluck('id')->all();

        $this->assertSame([$canonical->id], $ids);
    }

    public function test_ticket_form_department_list_excludes_the_archived_duplicate(): void
    {
        $canonical = $this->dept('ai-transformation');
        $this->archivedDuplicate();

        $ids = collect($this->actingAs($this->user())->getJson('/api/v1/tickets/meta')->assertOk()->json('departments'))->pluck('id')->all();

        $this->assertSame([$canonical->id], $ids);
    }

    // ── Admin management still sees what it needs ───────────────────────────

    public function test_admin_can_still_open_an_archived_department_by_id(): void
    {
        $this->dept('ai-transformation');
        $archived = $this->archivedDuplicate();

        $this->actingAs($this->user('super_admin'))->getJson("/api/admin/departments/{$archived->id}")
            ->assertOk()->assertJsonPath('data.id', $archived->id);
    }

    public function test_admin_management_list_shows_the_canonical_department_once(): void
    {
        $canonical = $this->dept('ai-transformation');
        $this->archivedDuplicate();

        $ids = collect($this->actingAs($this->user('super_admin'))->getJson('/api/admin/departments')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$canonical->id], $ids);
    }

    // ── Creating a duplicate is refused ─────────────────────────────────────

    public function test_creating_a_department_with_an_existing_name_is_refused(): void
    {
        $existing = $this->dept('ai-transformation');

        $this->actingAs($this->user('super_admin'))
            ->postJson('/api/admin/departments', ['name' => self::AI, 'slug' => 'brand-new-slug'])
            ->assertStatus(422)
            ->assertJsonPath('conflicting_department_id', $existing->id);

        $this->assertSame(1, Department::count());
    }

    public function test_a_spacing_or_letter_variant_of_an_existing_name_is_also_refused(): void
    {
        $this->dept('ai-transformation');

        foreach (["إدارة  الذكاء الاصطناعي و التحول الرقمي ", 'ادارة الذكاء الاصطناعي والتحول الرقمي'] as $variant) {
            $this->actingAs($this->user('super_admin'))
                ->postJson('/api/admin/departments', ['name' => $variant, 'slug' => 'v-'.md5($variant)])
                ->assertStatus(422);
        }
        $this->assertSame(1, Department::count());
    }

    public function test_an_english_name_that_matches_is_refused(): void
    {
        $this->dept('ai-transformation');

        $this->actingAs($this->user('super_admin'))
            ->postJson('/api/admin/departments', ['name' => 'ai  digital transformation', 'slug' => 'x'])
            ->assertStatus(422)->assertJsonPath('success', false);
        // (English "AI & Digital Transformation" normalises to "ai digital transformation".)
    }

    public function test_a_genuinely_new_department_is_still_created(): void
    {
        $this->dept('ai-transformation');

        $this->actingAs($this->user('super_admin'))
            ->postJson('/api/admin/departments', ['name' => 'إدارة البحث والتطوير', 'slug' => 'research'])
            ->assertCreated();
    }

    public function test_a_name_held_only_by_an_archived_department_can_be_reused(): void
    {
        $this->archivedDuplicate();

        $this->actingAs($this->user('super_admin'))
            ->postJson('/api/admin/departments', ['name' => self::AI, 'slug' => 'ai-transformation'])
            ->assertCreated();
    }

    public function test_renaming_into_an_existing_name_is_refused_but_keeping_your_own_is_fine(): void
    {
        $this->dept('ai-transformation');
        $other = $this->dept('other', ['name' => 'إدارة أخرى', 'name_ar' => 'إدارة أخرى', 'name_en' => 'Other']);
        $admin = $this->user('super_admin');

        $this->actingAs($admin)->patchJson("/api/admin/departments/{$other->id}", ['name' => self::AI])->assertStatus(422);
        $this->actingAs($admin)->patchJson("/api/admin/departments/{$other->id}", ['name' => 'إدارة أخرى', 'sort_order' => 9])->assertOk();
        $this->actingAs($admin)->patchJson("/api/admin/departments/{$other->id}", ['sort_order' => 3])->assertOk();
    }

    public function test_reactivating_an_archived_duplicate_is_refused_while_the_canonical_exists(): void
    {
        $this->dept('ai-transformation');
        $archived = $this->archivedDuplicate();

        $this->actingAs($this->user('super_admin'))
            ->patchJson("/api/admin/departments/{$archived->id}", ['status' => 'active'])
            ->assertStatus(422);

        $this->assertSame('archived', $archived->fresh()->status);
    }

    // ── The official sync must not recreate the duplicate ───────────────────

    public function test_official_sync_does_not_create_a_same_name_department_under_a_new_slug(): void
    {
        // The official slug is ai-transformation; production already has this
        // exact Arabic name under ai-digital-transformation.
        $this->dept('ai-digital-transformation');
        $before = Department::count();

        $this->artisan('departments:sync-official', ['--apply' => true])
            ->expectsOutputToContain('NOT created because a department with the same name already exists')
            ->assertSuccessful();

        $this->assertNull(Department::where('slug', 'ai-transformation')->first(), 'must not add a second AI department');
        $this->assertSame(
            $before + count(\Database\Seeders\OfficialDepartmentsSeeder::DEPARTMENTS) - 1,
            Department::count(),
            'every other official department is still created — only the same-name one is skipped',
        );
    }
}
