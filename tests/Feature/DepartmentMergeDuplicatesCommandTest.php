<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\DepartmentPageDefault;
use App\Models\Meeting;
use App\Models\MeetingReport;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserDepartment;
use App\Models\WeeklyDepartmentReport;
use App\Services\DepartmentMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * departments:merge-duplicates — safe, transactional, dry-run-by-default merge.
 *
 * Rows are built to mirror the shape that matters in production: two departments
 * with the SAME Arabic name and different slugs, where the canonical one has the
 * leader and the duplicate holds a mix of live and historical records.
 */
class DepartmentMergeDuplicatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const AI = 'إدارة الذكاء الاصطناعي والتحول الرقمي';

    private function dept(string $slug, array $over = []): Department
    {
        return Department::create(array_merge([
            'name' => self::AI, 'name_ar' => self::AI, 'name_en' => null,
            'slug' => $slug, 'status' => 'active', 'is_active' => true,
        ], $over));
    }

    /** @return array{0: Department, 1: Department} canonical (led) and duplicate */
    private function pair(array $canonicalOver = [], array $duplicateOver = []): array
    {
        $leader = User::factory()->create();

        return [
            $this->dept('ai-transformation', array_merge(['leader_id' => $leader->id], $canonicalOver)),
            $this->dept('ai-digital-transformation', $duplicateOver),
        ];
    }

    private function merge(array $args, bool $apply = true)
    {
        return $this->artisan('departments:merge-duplicates', $args + ($apply ? ['--apply' => true] : []));
    }

    private function profile(Department $d, array $over = []): TeamProfile
    {
        return TeamProfile::create(array_merge([
            'department_id' => $d->id, 'user_id' => User::factory()->create()->id,
            'name_ar' => 'عضو', 'position_ar' => 'Member', 'is_active' => true,
        ], $over));
    }

    /** Every referencing row in the database, keyed by "table" => count, for before/after comparison. */
    private function snapshot(): array
    {
        $out = ['departments' => Department::count()];
        foreach (array_merge(DepartmentMergeService::MOVE, DepartmentMergeService::PRESERVE) as $table => $column) {
            $out[$table] = DB::table($table)->count();
        }

        return $out;
    }

    // ── Dry run ─────────────────────────────────────────────────────────────

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        [$canonical, $dup] = $this->pair();
        $profile = $this->profile($dup);
        Ticket::create(['ticket_number' => 'T-1', 'title' => 't', 'description' => 'd', 'department_id' => $dup->id, 'created_by_name' => 'x']);
        $before = $this->snapshot();

        $this->merge(['--name' => self::AI], apply: false)
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('WILL_MOVE')
            ->expectsOutputToContain('STAYS (historical)')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame($dup->id, $profile->fresh()->department_id);
        $this->assertSame('active', $dup->fresh()->status);
    }

    public function test_explicit_dry_run_flag_behaves_identically(): void
    {
        [$canonical, $dup] = $this->pair();
        $this->profile($dup);

        $this->merge(['--name' => self::AI, '--dry-run' => true], apply: false)->assertSuccessful();

        $this->assertSame($dup->id, TeamProfile::first()->department_id);
    }

    public function test_apply_and_dry_run_together_are_rejected(): void
    {
        $this->pair();
        $this->merge(['--name' => self::AI, '--dry-run' => true])->assertFailed();
    }

    // ── What moves ──────────────────────────────────────────────────────────

    public function test_apply_moves_live_records_and_deletes_a_reference_free_duplicate(): void
    {
        [$canonical, $dup] = $this->pair();
        $creator = User::factory()->create();

        $profile = $this->profile($dup);
        $unit = DepartmentalUnit::create(['department_id' => $dup->id, 'code' => 'AIU1', 'name_ar' => 'وحدة', 'is_active' => true]);
        $member = TeamMember::create(['user_id' => $profile->user_id, 'team_profile_id' => $profile->id, 'department_id' => $dup->id, 'unit_id' => $unit->id, 'status' => 'active']);
        $course = Course::create(['title' => 'c', 'slug' => 'c1', 'department_id' => $dup->id]);
        $task = Task::create(['title' => 't', 'department_id' => $dup->id, 'created_by' => $creator->id]);
        $meeting = Meeting::create(['title' => 'm', 'department_id' => $dup->id, 'organizer_id' => $creator->id, 'meeting_date' => now()->toDateString(), 'start_time' => '10:00']);
        $before = $this->snapshot();

        $this->merge(['--name' => self::AI])->expectsOutputToContain('DELETED')->assertSuccessful();

        foreach ([$profile, $unit, $member, $course, $task, $meeting] as $row) {
            $this->assertSame($canonical->id, $row->fresh()->department_id, class_basename($row).' must move to the canonical department');
        }
        $this->assertTrue(DepartmentalUnit::where('id', $member->fresh()->unit_id)->where('department_id', $canonical->id)->exists());
        $this->assertNull(Department::find($dup->id));

        // No data loss: every row still exists, only the department count dropped by one.
        $after = $this->snapshot();
        $this->assertSame($before['departments'] - 1, $after['departments']);
        unset($before['departments'], $after['departments']);
        $this->assertSame($before, $after);
    }

    public function test_moved_profiles_never_keep_a_stale_leader_flag(): void
    {
        [$canonical, $dup] = $this->pair();
        $stale = $this->profile($dup, ['is_leader' => true]);

        $this->merge(['--name' => self::AI, '--leader' => 'canonical'])->assertSuccessful();

        $this->assertFalse($stale->fresh()->is_leader, 'is_leader is a display flag derived from departments.leader_id, never copied');
    }

    public function test_the_canonical_leaders_profile_is_flagged_after_it_moves(): void
    {
        [$canonical, $dup] = $this->pair();
        $leaderProfile = $this->profile($dup, ['user_id' => $canonical->leader_id, 'is_leader' => false]);

        $this->merge(['--name' => self::AI])->assertSuccessful();

        $this->assertTrue($leaderProfile->fresh()->is_leader);
        $this->assertSame($canonical->leader_id, $canonical->fresh()->leader_id);
    }

    // ── What stays ──────────────────────────────────────────────────────────

    public function test_historical_records_stay_and_the_duplicate_is_archived_and_hidden(): void
    {
        [$canonical, $dup] = $this->pair();
        $creator = User::factory()->create();

        $ticket = Ticket::create(['ticket_number' => 'T-1', 'title' => 't', 'description' => 'd', 'department_id' => $dup->id, 'created_by_name' => 'x']);
        $meetingReport = MeetingReport::create(['department_id' => $dup->id, 'title' => 'r', 'meeting_date' => now()->toDateString(), 'created_by' => $creator->id]);
        $weekly = WeeklyDepartmentReport::create(['department_id' => $dup->id, 'week_start' => now()->startOfWeek()->toDateString(), 'submitted_by' => $creator->id, 'achievements' => 'a', 'planned' => 'p', 'submitted_at' => now()]);

        $this->merge(['--name' => self::AI])->expectsOutputToContain('ARCHIVED')->assertSuccessful();

        foreach ([$ticket, $meetingReport, $weekly] as $row) {
            $this->assertSame($dup->id, $row->fresh()->department_id, class_basename($row).' is history and must not be re-pointed');
        }
        $dup->refresh();
        $this->assertSame('archived', $dup->status);
        $this->assertFalse((bool) $dup->is_active, 'legacy is_active filters must hide it too');
    }

    public function test_move_historical_repoints_history_and_then_deletes(): void
    {
        [$canonical, $dup] = $this->pair();
        $ticket = Ticket::create(['ticket_number' => 'T-1', 'title' => 't', 'description' => 'd', 'department_id' => $dup->id, 'created_by_name' => 'x']);

        $this->merge(['--name' => self::AI, '--move-historical' => true])->assertSuccessful();

        $this->assertSame($canonical->id, $ticket->fresh()->department_id);
        $this->assertNull(Department::find($dup->id));
    }

    public function test_row_timestamps_are_preserved_by_a_merge(): void
    {
        [$canonical, $dup] = $this->pair();
        $profile = $this->profile($dup);
        DB::table('team_profiles')->where('id', $profile->id)->update(['updated_at' => '2026-01-02 03:04:05']);

        $this->merge(['--name' => self::AI])->assertSuccessful();

        $this->assertSame('2026-01-02 03:04:05', $profile->fresh()->updated_at->toDateTimeString());
    }

    // ── Constraint-safe folding ─────────────────────────────────────────────

    public function test_user_departments_fold_without_violating_the_unique_constraint(): void
    {
        [$canonical, $dup] = $this->pair();
        $both = User::factory()->create();
        $onlyDup = User::factory()->create();
        UserDepartment::create(['user_id' => $both->id, 'department_id' => $canonical->id, 'is_primary' => false]);
        UserDepartment::create(['user_id' => $both->id, 'department_id' => $dup->id, 'is_primary' => true]);
        UserDepartment::create(['user_id' => $onlyDup->id, 'department_id' => $dup->id, 'is_primary' => false]);

        $this->merge(['--name' => self::AI])->assertSuccessful();

        $this->assertSame(1, UserDepartment::where('user_id', $both->id)->count());
        $this->assertTrue((bool) UserDepartment::where('user_id', $both->id)->first()->is_primary, 'the primary flag must survive the fold');
        $this->assertSame($canonical->id, UserDepartment::where('user_id', $onlyDup->id)->value('department_id'));
    }

    public function test_page_defaults_fold_without_duplicating_or_losing_any_distinct_default(): void
    {
        [$canonical, $dup] = $this->pair();
        DepartmentPageDefault::create(['department_id' => $canonical->id, 'page_key' => 'operations.weekly_reports', 'audience' => 'member']);
        DepartmentPageDefault::create(['department_id' => $dup->id, 'page_key' => 'operations.weekly_reports', 'audience' => 'member']);
        DepartmentPageDefault::create(['department_id' => $dup->id, 'page_key' => 'operations.meetings', 'audience' => 'leader']);

        $this->merge(['--name' => self::AI])->assertSuccessful();

        $rows = DepartmentPageDefault::where('department_id', $canonical->id)->get()->map(fn ($r) => "{$r->page_key}|{$r->audience->value}")->sort()->values()->all();
        $this->assertSame(['operations.meetings|leader', 'operations.weekly_reports|member'], $rows);
        $this->assertSame(2, DepartmentPageDefault::count());
    }

    public function test_courses_are_moved_without_duplication(): void
    {
        [$canonical, $dup] = $this->pair();
        Course::create(['title' => 'a', 'slug' => 'a', 'department_id' => $dup->id]);
        Course::create(['title' => 'b', 'slug' => 'b', 'department_id' => $canonical->id]);

        $this->merge(['--name' => self::AI])->assertSuccessful();

        $this->assertSame(2, Course::where('department_id', $canonical->id)->count());
    }

    // ── Leadership authority ────────────────────────────────────────────────

    public function test_a_leader_conflict_blocks_everything_and_writes_nothing(): void
    {
        [$canonical, $dup] = $this->pair([], ['leader_id' => User::factory()->create()->id]);
        $this->profile($dup);
        $before = $this->snapshot();

        $this->merge(['--name' => self::AI])
            ->expectsOutputToContain('Leadership differs')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame($dup->id, TeamProfile::first()->department_id);
    }

    public function test_a_dry_run_with_a_conflict_also_exits_non_zero(): void
    {
        $this->pair([], ['leader_id' => User::factory()->create()->id]);

        $this->merge(['--name' => self::AI], apply: false)->assertFailed();
    }

    public function test_leader_canonical_keeps_the_canonical_leader_and_reports_the_displaced_one(): void
    {
        $displaced = User::factory()->create();
        [$canonical, $dup] = $this->pair([], ['leader_id' => $displaced->id]);
        $keep = $canonical->leader_id;

        $this->merge(['--name' => self::AI, '--leader' => 'canonical'])
            ->expectsOutputToContain('Displaced leadership')
            ->assertSuccessful();

        $this->assertSame($keep, $canonical->fresh()->leader_id);
    }

    public function test_leader_duplicate_takes_the_duplicates_leader_and_keeps_flags_consistent(): void
    {
        $newLeader = User::factory()->create();
        [$canonical, $dup] = $this->pair([], ['leader_id' => $newLeader->id]);
        $profile = $this->profile($dup, ['user_id' => $newLeader->id]);

        $this->merge(['--name' => self::AI, '--canonical-id' => $canonical->id, '--leader' => 'duplicate'])->assertSuccessful();

        $this->assertSame($newLeader->id, $canonical->fresh()->leader_id);
        $this->assertTrue($profile->fresh()->is_leader);
    }

    public function test_adopting_a_leader_into_a_leaderless_canonical_still_needs_an_explicit_choice(): void
    {
        $leader = User::factory()->create();
        $canonical = $this->dept('ai-transformation');
        $dup = $this->dept('ai-digital-transformation', ['leader_id' => $leader->id]);

        $pin = ['--name' => self::AI, '--canonical-id' => $canonical->id];

        $this->merge($pin)->assertFailed();
        $this->assertNull($canonical->fresh()->leader_id);

        $this->merge($pin + ['--leader' => 'duplicate'])->assertSuccessful();
        $this->assertSame($leader->id, $canonical->fresh()->leader_id);
    }

    public function test_the_same_leader_on_both_is_not_a_conflict(): void
    {
        $leader = User::factory()->create();
        $this->dept('ai-transformation', ['leader_id' => $leader->id]);
        $this->dept('ai-digital-transformation', ['leader_id' => $leader->id]);

        $this->merge(['--name' => self::AI])->assertSuccessful();
    }

    public function test_an_invalid_leader_option_is_rejected(): void
    {
        $this->pair();
        $this->merge(['--name' => self::AI, '--leader' => 'both'])->assertFailed();
    }

    // ── Idempotency ─────────────────────────────────────────────────────────

    public function test_a_repeated_apply_after_delete_is_a_no_op(): void
    {
        [$canonical, $dup] = $this->pair();
        $this->profile($dup);
        $this->merge(['--name' => self::AI])->assertSuccessful();
        $after = $this->snapshot();

        $this->merge(['--name' => self::AI])
            ->expectsOutputToContain('no duplicate to merge')
            ->assertSuccessful();

        $this->assertSame($after, $this->snapshot());
    }

    public function test_a_repeated_apply_after_archive_is_a_no_op(): void
    {
        [$canonical, $dup] = $this->pair();
        Ticket::create(['ticket_number' => 'T-1', 'title' => 't', 'description' => 'd', 'department_id' => $dup->id, 'created_by_name' => 'x']);
        $this->merge(['--name' => self::AI])->assertSuccessful();
        $after = $this->snapshot();

        $this->merge(['--canonical-id' => $canonical->id, '--duplicate-id' => $dup->id])
            ->expectsOutputToContain('already merged')
            ->assertSuccessful();

        $this->assertSame($after, $this->snapshot());
        $this->assertSame('archived', $dup->fresh()->status);
    }

    // ── Refusals ────────────────────────────────────────────────────────────

    public function test_refuses_to_merge_a_department_into_itself(): void
    {
        [$canonical] = $this->pair();
        $this->merge(['--canonical-id' => $canonical->id, '--duplicate-id' => $canonical->id], apply: false)->assertFailed();
    }

    public function test_refuses_unknown_ids(): void
    {
        [$canonical] = $this->pair();
        $this->merge(['--canonical-id' => $canonical->id, '--duplicate-id' => 99999], apply: false)->assertFailed();
        $this->merge(['--canonical-id' => 99999, '--duplicate-id' => $canonical->id], apply: false)->assertFailed();
    }

    public function test_refuses_ids_outside_a_duplicate_group_unless_forced(): void
    {
        $a = $this->dept('a', ['name' => 'الإدارة المالية', 'name_ar' => 'الإدارة المالية']);
        $b = $this->dept('b', ['name' => 'إدارة الإعلام والتسويق', 'name_ar' => 'إدارة الإعلام والتسويق']);

        $this->merge(['--canonical-id' => $a->id, '--duplicate-id' => $b->id])->assertFailed();
        $this->assertNotNull(Department::find($b->id));

        $this->merge(['--canonical-id' => $a->id, '--duplicate-id' => $b->id, '--force' => true])->assertSuccessful();
        $this->assertNull(Department::find($b->id));
    }

    public function test_requires_exactly_one_selection_mode(): void
    {
        $this->pair();
        $this->artisan('departments:merge-duplicates')->assertFailed();
        $this->artisan('departments:merge-duplicates', ['--name' => self::AI, '--all' => true])->assertFailed();
        $this->artisan('departments:merge-duplicates', ['--canonical-id' => 1])->assertFailed();
    }

    public function test_unknown_name_is_an_error_not_a_silent_success(): void
    {
        $this->pair();
        $this->merge(['--name' => 'قسم غير موجود'], apply: false)->assertFailed();
    }

    public function test_child_departments_block_the_merge(): void
    {
        [$canonical, $dup] = $this->pair();
        Department::create(['name' => 'ف', 'name_ar' => 'قسم فرعي', 'slug' => 'child', 'status' => 'active', 'is_active' => true, 'parent_id' => $dup->id]);

        $this->merge(['--name' => self::AI])->expectsOutputToContain('child department')->assertFailed();
        $this->assertNotNull(Department::find($dup->id));
    }

    public function test_cannot_merge_into_an_archived_canonical(): void
    {
        [$canonical, $dup] = $this->pair(['status' => 'archived']);

        $this->merge(['--canonical-id' => $canonical->id, '--duplicate-id' => $dup->id, '--force' => true])->assertFailed();
    }

    // ── Selection ───────────────────────────────────────────────────────────

    public function test_name_selection_tolerates_spacing_and_letter_variants(): void
    {
        [$canonical, $dup] = $this->pair();

        $this->merge(['--name' => "ادارة  الذكاء الاصطناعي و التحول الرقمي "])->assertSuccessful();

        $this->assertNull(Department::find($dup->id));
    }

    public function test_canonical_override_keeps_the_chosen_row(): void
    {
        [$led, $other] = $this->pair();

        $this->merge(['--name' => self::AI, '--canonical-id' => $other->id, '--leader' => 'duplicate'])->assertSuccessful();

        $this->assertNotNull(Department::find($other->id));
        $this->assertNull(Department::find($led->id));
    }

    public function test_duplicate_id_restricts_a_three_way_group_to_one_merge(): void
    {
        [$canonical, $dupA] = $this->pair();
        $dupB = $this->dept('ai-extra');

        $this->merge(['--name' => self::AI, '--duplicate-id' => $dupA->id])->assertSuccessful();

        $this->assertNull(Department::find($dupA->id));
        $this->assertNotNull(Department::find($dupB->id), 'only the named duplicate is merged');
    }

    public function test_the_recommendation_prefers_active_then_led_then_more_data_then_older(): void
    {
        $svc = app(DepartmentMergeService::class);

        $inactive = $this->dept('x1', ['status' => 'inactive', 'leader_id' => User::factory()->create()->id]);
        $activeBare = $this->dept('x2');
        $this->assertSame($activeBare->id, $svc->recommendCanonical(collect([$inactive, $activeBare]))['department']->id, 'active beats inactive even when inactive has a leader');

        $led = $this->dept('x3', ['leader_id' => User::factory()->create()->id]);
        $this->assertSame($led->id, $svc->recommendCanonical(collect([$activeBare, $led]))['department']->id, 'a leader beats none');

        $busy = $this->dept('x4');
        Course::create(['title' => 'c', 'slug' => 'cc', 'department_id' => $busy->id]);
        $this->assertSame($busy->id, $svc->recommendCanonical(collect([$activeBare, $busy]))['department']->id, 'more linked data wins');

        $older = $this->dept('x5');
        $newer = $this->dept('x6');
        DB::table('departments')->where('id', $older->id)->update(['created_at' => '2020-01-01 00:00:00']);
        $this->assertSame($older->id, $svc->recommendCanonical(collect([$newer->fresh(), $older->fresh()]))['department']->id, 'older wins a tie');
    }

    // ── --all and review tier ───────────────────────────────────────────────

    public function test_all_dry_run_lists_safe_groups_and_skips_review_pairs(): void
    {
        $this->pair();
        $this->dept('r1', ['name' => 'إدارة الصحة النفسية', 'name_ar' => 'إدارة الصحة النفسية']);
        $this->dept('r2', ['name' => 'إدارة الصحة النفسيه', 'name_ar' => 'إدارة الصحة النفسيه']);

        $this->merge(['--all' => true], apply: false)
            ->expectsOutputToContain('Skipping review-only pair')
            ->assertSuccessful();

        $this->assertSame(4, Department::count());
    }

    public function test_all_apply_merges_safe_groups_but_never_a_review_pair(): void
    {
        $this->pair();
        $this->dept('r1', ['name' => 'إدارة الصحة النفسية', 'name_ar' => 'إدارة الصحة النفسية']);
        $this->dept('r2', ['name' => 'إدارة الصحة النفسيه', 'name_ar' => 'إدارة الصحة النفسيه']);

        $this->merge(['--all' => true])->assertSuccessful();

        $this->assertSame(3, Department::count());
        $this->assertNotNull(Department::where('slug', 'r1')->first());
        $this->assertNotNull(Department::where('slug', 'r2')->first());
    }

    public function test_all_is_atomic_one_conflicting_group_blocks_every_group(): void
    {
        // Group 1 is clean; group 2 has a leader conflict.
        [$c1, $d1] = $this->pair();
        $p1 = $this->profile($d1);

        $c2 = $this->dept('fin-a', ['name' => 'الإدارة المالية', 'name_ar' => 'الإدارة المالية', 'leader_id' => User::factory()->create()->id]);
        $d2 = $this->dept('fin-b', ['name' => 'الإدارة المالية', 'name_ar' => 'الإدارة المالية', 'leader_id' => User::factory()->create()->id]);
        $before = $this->snapshot();

        $this->merge(['--all' => true])->assertFailed();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame($d1->id, $p1->fresh()->department_id, 'the clean group must not be applied either');
    }

    public function test_all_cannot_be_combined_with_id_overrides(): void
    {
        $this->pair();
        $this->merge(['--all' => true, '--canonical-id' => 1])->assertFailed();
    }

    // ── Schema guard ────────────────────────────────────────────────────────

    public function test_every_foreign_key_to_departments_is_classified(): void
    {
        // If a future migration adds a table that references departments and it is
        // not in MOVE or PRESERVE, merges refuse to run. This test fails first,
        // so the omission is fixed at development time, not in production.
        $this->assertSame([], app(DepartmentMergeService::class)->unclassifiedReferences());
    }

    public function test_an_unclassified_reference_blocks_the_merge(): void
    {
        [$canonical, $dup] = $this->pair();
        DB::statement('CREATE TABLE zz_new_feature (id integer primary key, department_id integer, FOREIGN KEY (department_id) REFERENCES departments(id))');

        $this->merge(['--name' => self::AI])
            ->expectsOutputToContain('Unclassified foreign key to departments: zz_new_feature.department_id')
            ->assertFailed();

        $this->assertNotNull(Department::find($dup->id));
    }

    public function test_the_two_page_access_tables_are_classified(): void
    {
        $this->assertArrayHasKey('department_page_defaults', DepartmentMergeService::MOVE);
        $this->assertSame('source_department_id', DepartmentMergeService::PRESERVE['user_page_access_overrides']);
    }

    // ── Post-merge integrity ────────────────────────────────────────────────

    public function test_after_a_merge_nothing_is_orphaned_and_leadership_authority_is_intact(): void
    {
        [$canonical, $dup] = $this->pair();
        $creator = User::factory()->create();
        $leader = User::find($canonical->leader_id);

        // A realistic mix: live + historical + folded rows on the duplicate.
        $profile = $this->profile($dup);
        DepartmentalUnit::create(['department_id' => $dup->id, 'code' => 'U1', 'name_ar' => 'وحدة', 'is_active' => true]);
        Course::create(['title' => 'c', 'slug' => 'c1', 'department_id' => $dup->id]);
        Task::create(['title' => 't', 'department_id' => $dup->id, 'created_by' => $creator->id]);
        UserDepartment::create(['user_id' => $profile->user_id, 'department_id' => $dup->id, 'is_primary' => true]);
        DepartmentPageDefault::create(['department_id' => $dup->id, 'page_key' => 'operations.meetings', 'audience' => 'member']);
        Ticket::create(['ticket_number' => 'T-1', 'title' => 't', 'description' => 'd', 'department_id' => $dup->id, 'created_by_name' => 'x']);

        $this->merge(['--name' => self::AI])->assertSuccessful();

        // 1. No broken department reference anywhere in the schema.
        $all = array_merge(DepartmentMergeService::MOVE, DepartmentMergeService::PRESERVE);
        foreach ($all as $table => $column) {
            $orphans = DB::table($table)->whereNotNull($column)->whereNotIn($column, DB::table('departments')->select('id'))->count();
            $this->assertSame(0, $orphans, "$table.$column points at a department that does not exist");
        }

        // 2. Nothing LIVE still references the duplicate (only archived history may).
        foreach (DepartmentMergeService::MOVE as $table => $column) {
            $this->assertSame(0, DB::table($table)->where($column, $dup->id)->count(), "$table still has live rows on the duplicate");
        }

        // 3. Leadership authority: departments.leader_id untouched, and the
        //    canonical access service resolves the leader to the canonical department only.
        $this->assertSame($leader->id, $canonical->fresh()->leader_id);
        $led = app(\App\Services\DepartmentAccessService::class)->getLedDepartmentIds($leader);
        $this->assertSame([$canonical->id], $led->values()->all());

        // 4. No duplicate remains in detection.
        $this->assertCount(0, app(\App\Services\DepartmentDuplicateDetector::class)->detect());
    }

    // ── Public effect ───────────────────────────────────────────────────────

    public function test_after_a_merge_the_department_appears_once_on_the_public_team_and_dropdowns(): void
    {
        [$canonical, $dup] = $this->pair(['sort_order' => 1], ['sort_order' => 2]);
        foreach ([$canonical, $dup] as $d) {
            DB::table('departments')->where('id', $d->id)->update(['name_en' => 'AI & Digital Transformation']);
        }
        $this->profile($dup, ['name_ar' => 'عضو من المكرر']);
        Ticket::create(['ticket_number' => 'T-1', 'title' => 't', 'description' => 'd', 'department_id' => $dup->id, 'created_by_name' => 'x']);

        $this->merge(['--name' => self::AI])->assertSuccessful();

        $team = $this->getJson('/api/team')->assertOk()->json('data');
        $this->assertCount(1, $team, 'exactly one AI department on /ar/team');
        $this->assertSame($canonical->id, $team[0]['id']);
        $this->assertSame(['عضو من المكرر'], collect($team[0]['members'])->pluck('name_ar')->all(), 'the moved member now appears under the canonical department');

        $this->actingAs(User::factory()->create())->getJson('/api/departments/options')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $canonical->id);
    }
}
