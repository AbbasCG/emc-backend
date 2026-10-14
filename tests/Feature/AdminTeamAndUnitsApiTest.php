<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The listing contract behind /dashboard/admin/team-members and /dashboard/admin/department-units, and the
 * department manifest the units page uses to pick its department.
 *
 * Authorization is checked at the API: the route gate for team members, department access for unit reads,
 * leadership for unit writes. Nothing here depends on what the screen chose to show.
 */
class AdminTeamAndUnitsApiTest extends TestCase
{
    use RefreshDatabase;

    private Department $deptA;
    private Department $deptB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->deptA = $this->dept('التقنية');
        $this->deptB = $this->dept('التسويق');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function dept(string $ar): Department
    {
        return Department::create(['name' => 'D '.Str::random(5), 'name_ar' => $ar, 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
    }

    private function user(string $role = 'student', array $over = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true], $over));
    }

    private function leader(Department $d): User
    {
        $u = $this->user('programs_manager');
        $d->update(['leader_id' => $u->id]);

        return $u;
    }

    private function unit(Department $d, array $over = []): DepartmentalUnit
    {
        return DepartmentalUnit::create(array_merge(['department_id' => $d->id, 'code' => 'U-'.Str::random(6), 'name_ar' => 'وحدة '.Str::random(3), 'is_active' => true], $over));
    }

    /** A canonical member row: user + profile + team_members. */
    private function member(Department $d, array $user = [], array $row = [], array $profile = []): TeamMember
    {
        $u = $this->user($user['role'] ?? 'instructor', $user);
        $p = TeamProfile::create(array_merge(['user_id' => $u->id, 'name_ar' => $u->name, 'position_ar' => 'مطور', 'department_id' => $d->id, 'member_type' => 'staff', 'is_active' => true], $profile));

        return TeamMember::create(array_merge(['user_id' => $u->id, 'team_profile_id' => $p->id, 'department_id' => $d->id, 'status' => 'active'], $row));
    }

    private function members(User $who, string $qs = '')
    {
        return $this->actingAs($who)->getJson('/api/admin/team-members'.($qs ? '?'.$qs : ''));
    }

    private function names($res): array
    {
        return array_column(array_column($res->json('data'), 'user'), 'name');
    }

    // ═══ Team members: who may call it ═══════════════════════════════════════

    public function test_only_the_global_admin_roles_may_list_team_members(): void
    {
        $this->member($this->deptA);

        $this->getJson('/api/admin/team-members')->assertStatus(401);
        foreach (['super_admin', 'admin', 'tech_admin'] as $role) {
            $this->members($this->user($role))->assertOk();
        }
        foreach (['student', 'instructor', 'hr_manager', 'executive_admin', 'support_agent'] as $role) {
            $this->members($this->user($role))->assertStatus(403);
        }
        // A department leader is not a global admin, whatever department they lead.
        $this->members($this->leader($this->deptA))->assertStatus(403);
    }

    public function test_writes_are_refused_to_everyone_outside_the_gate(): void
    {
        $row = $this->member($this->deptA);
        $leader = $this->leader($this->deptA);
        $student = $this->user();

        foreach ([$leader, $student] as $who) {
            $this->actingAs($who)->putJson("/api/admin/team-members/{$row->id}", ['status' => 'inactive'])->assertStatus(403);
            $this->actingAs($who)->postJson('/api/admin/team-members', ['user_id' => $student->id])->assertStatus(403);
            $this->actingAs($who)->deleteJson("/api/admin/team-members/{$row->id}")->assertStatus(403);
        }
        $this->assertSame('active', $row->fresh()->status);
    }

    // ═══ Team members: filtering, sorting, paging ════════════════════════════

    public function test_search_matches_name_or_email_and_treats_wildcards_literally(): void
    {
        $this->member($this->deptA, ['name' => 'سلمى الحربي', 'email' => 'salma@example.test']);
        $this->member($this->deptA, ['name' => 'خالد 100% نشط', 'email' => 'khaled@example.test']);
        $this->member($this->deptA, ['name' => 'نورة', 'email' => 'noura@example.test']);
        $admin = $this->user('admin');

        $this->assertSame(['سلمى الحربي'], $this->names($this->members($admin, 'search='.urlencode('سلمى'))));
        $this->assertSame(['نورة'], $this->names($this->members($admin, 'search=noura@')));
        $this->assertSame(['خالد 100% نشط'], $this->names($this->members($admin, 'search='.urlencode('%'))));
        $this->assertSame([], $this->names($this->members($admin, 'search='.urlencode('_'))));
    }

    public function test_department_status_unit_and_role_filters(): void
    {
        $unit = $this->unit($this->deptA);
        $a1 = $this->member($this->deptA, ['name' => 'أ', 'role' => 'instructor'], ['unit_id' => $unit->id]);
        $a2 = $this->member($this->deptA, ['name' => 'ب', 'role' => 'student'], ['status' => 'on_leave']);
        $b1 = $this->member($this->deptB, ['name' => 'ج', 'role' => 'instructor'], ['status' => 'inactive']);
        $admin = $this->user('admin');

        $this->assertEqualsCanonicalizing(['أ', 'ب'], $this->names($this->members($admin, 'department_id='.$this->deptA->id)));
        $this->assertSame(['ج'], $this->names($this->members($admin, 'department_id='.$this->deptB->id)));
        $this->assertSame(['ب'], $this->names($this->members($admin, 'status=on_leave')));
        $this->assertSame(['أ'], $this->names($this->members($admin, 'unit_id='.$unit->id)));
        $this->assertSame(['ب'], $this->names($this->members($admin, 'department_id='.$this->deptA->id.'&unit_id=null')));
        $this->assertEqualsCanonicalizing(['أ', 'ج'], $this->names($this->members($admin, 'role=instructor')));
        $this->assertSame(['ج'], $this->names($this->members($admin, 'role=instructor&status=inactive')));
        $this->assertNotNull($a1->id.$a2->id.$b1->id);
    }

    public function test_invalid_parameters_are_rejected(): void
    {
        $admin = $this->user('admin');

        $this->members($admin, 'status=fired')->assertStatus(422);
        $this->members($admin, 'sort=password')->assertStatus(422);
        $this->members($admin, 'dir=sideways')->assertStatus(422);
        $this->members($admin, 'per_page=500')->assertStatus(422);
        $this->members($admin, 'department_id=abc')->assertStatus(422);
    }

    public function test_sorting_by_name_department_unit_and_status_with_a_stable_order(): void
    {
        $u1 = $this->unit($this->deptA, ['name_ar' => 'وحدة ألف']);
        $u2 = $this->unit($this->deptB, ['name_ar' => 'وحدة باء']);
        $this->member($this->deptB, ['name' => 'ب'], ['unit_id' => $u2->id, 'status' => 'inactive']);
        $this->member($this->deptA, ['name' => 'أ'], ['unit_id' => $u1->id, 'status' => 'active']);
        $this->member($this->deptA, ['name' => 'ت'], ['status' => 'on_leave']);
        $admin = $this->user('admin');

        $this->assertSame(['أ', 'ب', 'ت'], $this->names($this->members($admin, 'sort=name&dir=asc')));
        $this->assertSame(['ت', 'ب', 'أ'], $this->names($this->members($admin, 'sort=name&dir=desc')));
        $this->assertSame(['أ', 'ب'], array_slice($this->names($this->members($admin, 'sort=unit&dir=asc')), 0, 2), 'a member without a unit sorts last');
        $this->assertSame('ت', $this->names($this->members($admin, 'sort=unit&dir=asc'))[2]);
        $this->assertSame(['ت', 'أ', 'ب'], $this->names($this->members($admin, 'sort=department&dir=desc')), 'by department name; ties newest first');
        $this->assertSame('أ', $this->names($this->members($admin, 'sort=status&dir=asc'))[0], 'active sorts before inactive and on_leave');
    }

    public function test_pagination_reports_the_filtered_total_and_keeps_the_filter(): void
    {
        foreach (range(1, 13) as $i) {
            $this->member($this->deptA, ['name' => sprintf('عضو %02d', $i)]);
        }
        foreach (range(1, 4) as $i) {
            $this->member($this->deptB);
        }
        $admin = $this->user('admin');

        $p1 = $this->members($admin, 'department_id='.$this->deptA->id.'&per_page=5&sort=name&dir=asc&page=1');
        $p3 = $this->members($admin, 'department_id='.$this->deptA->id.'&per_page=5&sort=name&dir=asc&page=3');

        $this->assertSame(13, $p1->json('meta.total'));
        $this->assertSame(3, $p1->json('meta.last_page'));
        $this->assertSame(5, $p1->json('meta.per_page'));
        $this->assertSame(['عضو 01', 'عضو 02', 'عضو 03', 'عضو 04', 'عضو 05'], $this->names($p1));
        $this->assertSame(['عضو 11', 'عضو 12', 'عضو 13'], $this->names($p3), 'page 3 is still the filtered, sorted set');
    }

    public function test_the_summary_counts_the_whole_scope_and_ignores_the_status_and_unit_filters(): void
    {
        $unit = $this->unit($this->deptA);
        $this->member($this->deptA, [], ['unit_id' => $unit->id]);
        $this->member($this->deptA, [], ['status' => 'inactive']);
        $this->member($this->deptA, [], ['status' => 'on_leave']);
        $this->member($this->deptA);
        $this->member($this->deptB);
        $admin = $this->user('admin');

        $all = $this->members($admin, 'department_id='.$this->deptA->id.'&per_page=5')->json('meta.stats');
        $filtered = $this->members($admin, 'department_id='.$this->deptA->id.'&status=inactive&unit_id=null&per_page=5');

        $this->assertSame(['total' => 4, 'active' => 2, 'inactive' => 1, 'on_leave' => 1, 'without_unit' => 3], $all);
        $this->assertSame($all, $filtered->json('meta.stats'), 'pressing a card does not change the card numbers');
        $this->assertSame(1, $filtered->json('meta.total'));
        $this->assertSame(5, $this->members($admin)->json('meta.stats.total'), 'without a department filter the scope is everyone');
    }

    public function test_the_summary_follows_the_search_and_role_scope(): void
    {
        $this->member($this->deptA, ['name' => 'سلمى', 'role' => 'instructor']);
        $this->member($this->deptA, ['name' => 'خالد', 'role' => 'student']);
        $admin = $this->user('admin');

        $this->assertSame(1, $this->members($admin, 'search='.urlencode('سلمى'))->json('meta.stats.total'));
        $this->assertSame(1, $this->members($admin, 'role=student')->json('meta.stats.total'));
    }

    public function test_the_listing_does_not_query_per_row(): void
    {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->members($this->user('admin'), 'per_page=100&sort=name')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        foreach (range(1, 3) as $_) {
            $this->member($this->deptA, [], ['unit_id' => $this->unit($this->deptA)->id]);
        }
        $queries();
        $small = $queries();
        foreach (range(1, 25) as $_) {
            $this->member($this->deptA, [], ['unit_id' => $this->unit($this->deptA)->id]);
        }

        $this->assertSame($small, $queries());
    }

    public function test_editing_a_member_keeps_the_existing_rules(): void
    {
        $row = $this->member($this->deptA);
        $foreignUnit = $this->unit($this->deptB);
        $ownUnit = $this->unit($this->deptA);
        $admin = $this->user('admin');

        $this->actingAs($admin)->putJson("/api/admin/team-members/{$row->id}", ['unit_id' => $foreignUnit->id])->assertStatus(422);
        $this->actingAs($admin)->putJson("/api/admin/team-members/{$row->id}", ['status' => 'retired'])->assertStatus(422);
        $this->actingAs($admin)->putJson("/api/admin/team-members/{$row->id}", ['unit_id' => $ownUnit->id, 'status' => 'on_leave'])->assertOk()->assertJsonPath('data.status', 'on_leave');
        $this->assertSame($ownUnit->id, $row->fresh()->unit_id);
    }

    // ═══ Units: reads are scoped, writes are leadership ══════════════════════

    private function unitsOf(User $who, ?Department $d = null)
    {
        return $this->actingAs($who)->getJson('/api/admin/departmental-units'.($d ? '?department_id='.$d->id : ''));
    }

    public function test_a_user_reads_only_units_of_departments_they_may_access(): void
    {
        $a = $this->unit($this->deptA, ['name_ar' => 'وحدة التقنية']);
        $b = $this->unit($this->deptB, ['name_ar' => 'وحدة التسويق']);
        $leaderA = $this->leader($this->deptA);

        $res = $this->unitsOf($leaderA, $this->deptA)->assertOk();
        $this->assertSame([$a->id], array_column($res->json('data'), 'id'));

        $this->unitsOf($leaderA, $this->deptB)->assertStatus(403);
        // With no department named, a restricted user still receives only their own.
        $this->assertSame([$a->id], array_column($this->unitsOf($leaderA)->json('data'), 'id'));
        $this->assertNotContains($b->id, array_column($this->unitsOf($leaderA)->json('data'), 'id'));
    }

    public function test_a_member_may_read_their_departments_units_but_not_manage_them(): void
    {
        $unit = $this->unit($this->deptA);
        $member = $this->user('department_manager'); // passes the route's role gate, but leads nothing
        $this->deptA->users()->attach($member->id);

        $this->assertSame($unit->id, $this->unitsOf($member)->assertOk()->json('data.0.id'));
        $this->assertFalse($this->unitsOf($member, $this->deptA)->json('meta.can_manage'));
        $this->actingAs($member)->postJson('/api/admin/departmental-units', ['department_id' => $this->deptA->id, 'code' => 'X1', 'name_ar' => 'جديدة'])->assertStatus(403);
    }

    public function test_global_and_read_only_global_roles_read_every_department(): void
    {
        $a = $this->unit($this->deptA);
        $b = $this->unit($this->deptB);

        foreach (['admin', 'super_admin', 'tech_admin', 'quality_manager'] as $role) {
            $ids = array_column($this->unitsOf($this->user($role))->assertOk()->json('data'), 'id');
            $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids, $role);
            $this->unitsOf($this->user($role), $this->deptB)->assertOk();
        }
    }

    public function test_can_manage_is_reported_by_the_server_per_department(): void
    {
        $this->unit($this->deptA);
        $leaderA = $this->leader($this->deptA);
        $quality = $this->user('quality_manager');

        $this->assertTrue($this->unitsOf($leaderA, $this->deptA)->json('meta.can_manage'));
        $this->assertTrue($this->unitsOf($this->user('admin'), $this->deptA)->json('meta.can_manage'));
        $this->assertFalse($this->unitsOf($quality, $this->deptA)->json('meta.can_manage'), 'read-only global may read but not manage');
        $this->assertFalse($this->unitsOf($leaderA)->json('meta.can_manage'), 'no department named, nothing to manage');
    }

    public function test_unit_writes_stay_with_the_leader_of_that_department(): void
    {
        $unit = $this->unit($this->deptA);
        $leaderA = $this->leader($this->deptA);
        $leaderB = $this->leader($this->deptB);

        $this->actingAs($leaderB)->putJson("/api/admin/departmental-units/{$unit->id}", ['name_ar' => 'اختراق'])->assertStatus(403);
        $this->actingAs($leaderB)->postJson('/api/admin/departmental-units', ['department_id' => $this->deptA->id, 'code' => 'HX', 'name_ar' => 'اختراق'])->assertStatus(403);
        $this->actingAs($leaderA)->putJson("/api/admin/departmental-units/{$unit->id}", ['name_ar' => 'اسم جديد', 'description' => 'وصف'])->assertOk()->assertJsonPath('data.name_ar', 'اسم جديد');
        $this->actingAs($leaderA)->postJson('/api/admin/departmental-units', ['department_id' => $this->deptA->id, 'code' => 'OK1', 'name_ar' => 'جديدة'])->assertCreated();
        $this->assertSame('اسم جديد', $unit->fresh()->name_ar);
    }

    public function test_invalid_unit_input_is_rejected_with_field_errors(): void
    {
        $leader = $this->leader($this->deptA);
        $this->unit($this->deptA, ['code' => 'DUP']);

        $this->actingAs($leader)->postJson('/api/admin/departmental-units', ['department_id' => $this->deptA->id, 'code' => 'DUP', 'name_ar' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->actingAs($leader)->postJson('/api/admin/departmental-units', ['department_id' => $this->deptA->id])->assertStatus(422)->assertJsonValidationErrors(['code', 'name_ar']);
        $this->actingAs($leader)->getJson('/api/admin/departmental-units?department_id=abc')->assertStatus(422);
    }

    // ═══ The department manifest: primary department ═════════════════════════

    private function manifest(User $u): array
    {
        $json = $this->actingAs($u)->getJson('/api/operations/departments/my-access')->assertOk()->json();

        return $json['data'] ?? $json;
    }

    public function test_the_manifest_offers_an_unambiguous_primary_department_inside_the_read_scope(): void
    {
        $u = $this->user('programs_manager');
        $this->deptA->users()->attach($u->id, ['is_primary' => true]);
        $this->deptB->users()->attach($u->id, ['is_primary' => false]);

        $m = $this->manifest($u);

        $this->assertSame($this->deptA->id, $m['primary_department_id']);
        $this->assertEqualsCanonicalizing([$this->deptA->id, $this->deptB->id], array_column($m['readable_departments'], 'id'));
    }

    public function test_the_manifest_has_no_primary_when_it_is_missing_ambiguous_or_unreadable(): void
    {
        $none = $this->user('programs_manager');
        $this->deptA->users()->attach($none->id);
        $this->assertNull($this->manifest($none)['primary_department_id']);

        $two = $this->user('programs_manager');
        $this->deptA->users()->attach($two->id, ['is_primary' => true]);
        $this->deptB->users()->attach($two->id, ['is_primary' => true]);
        $this->assertNull($this->manifest($two)['primary_department_id'], 'two primaries are ambiguous, not a guess');

        $archived = $this->dept('مؤرشفة');
        $archived->update(['status' => 'archived', 'is_active' => false]);
        $gone = $this->user('programs_manager');
        $archived->users()->attach($gone->id, ['is_primary' => true]);
        $this->assertNull($this->manifest($gone)['primary_department_id'], 'a primary that is not offered to this user is not offered as the default');
    }

    public function test_global_roles_are_never_steered_into_a_primary_department(): void
    {
        foreach (['admin', 'super_admin', 'tech_admin', 'quality_manager'] as $role) {
            $u = $this->user($role);
            $this->deptA->users()->attach($u->id, ['is_primary' => true]);

            $this->assertNull($this->manifest($u)['primary_department_id'], $role);
        }
    }

    public function test_department_options_keep_their_name_and_add_the_arabic_display_name(): void
    {
        $leader = $this->leader($this->deptA);
        $options = $this->manifest($leader)['readable_departments'];
        $mine = collect($options)->firstWhere('id', $this->deptA->id);

        $this->assertSame($this->deptA->name, $mine['name'], 'the existing key is untouched');
        $this->assertArrayHasKey('name_ar', $mine);
        $this->assertSame($this->deptA->name_ar, $mine['name_ar']);
    }

    public function test_the_existing_manifest_keys_are_unchanged(): void
    {
        $leader = $this->leader($this->deptA);
        $m = $this->manifest($leader);

        foreach (['department_scope', 'allowed_departments', 'can_select_any_department', 'can_create_report', 'read_scope', 'readable_departments', 'can_view_multiple_departments'] as $k) {
            $this->assertArrayHasKey($k, $m);
        }
        $this->assertSame([$this->deptA->id], array_column($m['allowed_departments'], 'id'));
    }

    // ═══ Suggestions: no longer one query per candidate ══════════════════════

    public function test_assignment_suggestions_do_not_query_per_candidate(): void
    {
        $leader = $this->leader($this->deptA);
        $t = Ticket::create(['ticket_number' => 'EMC-TCK-S-'.Str::random(6), 'title' => 'x', 'description' => 'x', 'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM', 'department_id' => $this->deptA->id, 'created_by_name' => 'x', 'status' => 'ASSIGNED']);
        $count = function () use ($leader, $t): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($leader)->getJson("/api/v1/tickets/{$t->id}/assignment-suggestions")->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        foreach (range(1, 2) as $_) {
            $this->deptA->users()->attach($this->user()->id);
        }
        $count();
        $small = $count();
        foreach (range(1, 20) as $_) {
            $this->deptA->users()->attach($this->user()->id);
        }

        $this->assertSame($small, $count());
    }
}
