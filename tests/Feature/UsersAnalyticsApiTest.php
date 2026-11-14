<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Database\Seeders\GranularPermissionsSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UsersAnalyticsPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Users Management hub — analytics APIs (/api/admin/users-analytics/*).
 *
 * Fixed clock: «now» is 2026-11-20 12:00 UTC and the reporting zone is Europe/Amsterdam (UTC+1 in November), so a login
 * at 23:30 UTC belongs to the NEXT local day. The reporting window used throughout is 2026-11-10 … 2026-11-16.
 */
class UsersAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/users-analytics';
    private const FROM = '2026-11-10';
    private const TO = '2026-11-16';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-20 12:00:00');
        \Illuminate\Support\Carbon::setTestNow('2026-11-20 12:00:00');
        config(['users-analytics.timezone' => 'Europe/Amsterdam', 'users-analytics.cache_ttl' => 0]);

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, GranularPermissionsSeeder::class, UsersAnalyticsPermissionSeeder::class]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function user(string $role, string $createdUtc = '2026-08-01 08:00:00', array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role, 'is_active' => true, 'created_at' => $createdUtc, 'updated_at' => $createdUtc,
        ], $attrs));
    }

    private function login(User $u, string $utc, string $action = 'LOGIN', ?string $role = null): void
    {
        AuditLog::unguarded(fn () => AuditLog::create([
            'user_id' => $u->id, 'user_name' => $u->name, 'user_email' => $u->email, 'user_role' => $role ?? $u->role,
            'action' => $action, 'entity_type' => 'User', 'entity_id' => $u->id, 'created_at' => $utc, 'updated_at' => $utc,
        ]));
    }

    private function read(User $as, string $path, array $query = [])
    {
        return $this->actingAs($as->fresh() ?? $as)->getJson(self::BASE.'/'.$path.($query ? '?'.http_build_query($query) : ''));
    }

    private function window(array $extra = []): array
    {
        return array_merge(['from' => self::FROM, 'to' => self::TO], $extra);
    }

    /** The reference data set described in the header comment of each assertion block. */
    private function scenario(): User
    {
        $root = $this->user('super_admin', '2026-08-01 08:00:00');

        $a = $this->user('student', '2026-10-01 10:00:00', ['country_code' => 'NL', 'last_login_at' => '2026-11-10 09:40:00']);   // 10 days ago
        $b = $this->user('student', '2026-11-11 10:00:00', ['country_code' => 'NL', 'last_login_at' => '2026-10-11 12:00:00']);   // 40 days
        $c = $this->user('instructor', '2026-11-12 23:30:00', ['country_code' => 'SA', 'last_login_at' => '2026-09-11 12:00:00']); // 70 days (created 00:30 on the 13th, local)
        $d = $this->user('student', '2026-11-15 08:00:00');
        $e = $this->user('student', '2026-11-05 10:00:00', ['country_code' => null, 'country' => 'Yemen', 'last_login_at' => '2026-08-12 12:00:00']); // 100 days
        $this->user('instructor', '2026-09-01 08:00:00', ['is_active' => false, 'email_verified_at' => null, 'country_code' => null, 'country' => null]);
        $d->delete();

        // window logins
        $this->login($a, '2026-11-09 23:30:00');            // local 10th 00:30 → inside
        $this->login($a, '2026-11-10 09:00:00');
        $this->login($a, '2026-11-10 09:40:00');            // same UTC hour as the previous one
        $this->login($b, '2026-11-11 11:00:00');
        $this->login($c, '2026-11-12 23:45:00', 'LOGIN', 'instructor'); // local 13th 00:45
        $this->login($a, '2026-11-12 08:00:00');
        // outside / not a successful login
        $this->login($a, '2026-11-16 23:30:00');            // local 17th 00:30 → outside
        $this->login($a, '2026-11-11 10:00:00', 'LOGIN_FAILED');
        // previous window (3rd … 9th)
        $this->login($e, '2026-11-05 10:00:00');
        $this->login($a, '2026-11-04 10:00:00');

        return $root;
    }

    // ── authorization ────────────────────────────────────────────────────────

    public function test_guests_are_unauthenticated_on_every_endpoint(): void
    {
        foreach (['overview', 'activity', 'acquisition', 'last-logins'] as $path) {
            $this->getJson(self::BASE.'/'.$path)->assertUnauthorized();
        }
    }

    public function test_super_admin_and_tech_admin_pass_every_endpoint(): void
    {
        foreach (['super_admin', 'tech_admin'] as $role) {
            $u = $this->user($role);
            foreach (['overview', 'activity', 'acquisition', 'last-logins'] as $path) {
                $this->read($u, $path)->assertOk();
            }
        }
    }

    public function test_root_does_not_depend_on_the_permission_rows(): void
    {
        Permission::whereIn('name', [Permissions::USERS_ANALYTICS_VIEW, Permissions::USERS_ACTIVITY_VIEW, Permissions::USERS_ACQUISITION_VIEW])->delete();

        $this->read($this->user('super_admin'), 'overview')->assertOk();
        $this->read($this->user('tech_admin'), 'activity')->assertOk();
    }

    public function test_seeing_the_users_table_is_not_enough(): void
    {
        $admin = $this->user('admin');
        $this->assertTrue($admin->hasPermission(Permissions::VIEW_USERS), 'admin can see the users table…');
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();

        foreach (['overview', 'activity', 'acquisition', 'last-logins'] as $path) {
            $this->read($admin, $path)->assertForbidden(); // …but none of the analytics
        }
    }

    public function test_no_role_gets_the_keys_by_default_except_super_admin_and_tech_admin(): void
    {
        $keys = [Permissions::USERS_ANALYTICS_VIEW, Permissions::USERS_ACTIVITY_VIEW, Permissions::USERS_ACQUISITION_VIEW];

        foreach (Role::with('permissions')->get() as $role) {
            $granted = array_values(array_intersect($keys, $role->permissions->pluck('name')->all()));
            if (in_array($role->name, ['super_admin', 'tech_admin'], true)) {
                $this->assertEqualsCanonicalizing($keys, $granted, "{$role->name} must hold all three");
            } else {
                $this->assertSame([], $granted, "{$role->name} must hold none by default");
            }
        }
    }

    public function test_every_other_role_is_forbidden_by_default(): void
    {
        foreach (Role::query()->pluck('name') as $name) {
            if (in_array($name, ['super_admin', 'tech_admin'], true)) {
                continue;
            }
            foreach (['overview', 'activity', 'acquisition', 'last-logins'] as $path) {
                $this->read($this->user($name), $path)->assertForbidden();
            }
        }
    }

    public function test_each_tab_has_its_own_key(): void
    {
        $analyticsOnly = $this->user('admin');
        $this->grantToRole('admin', [Permissions::USERS_ANALYTICS_VIEW]);

        $this->read($analyticsOnly, 'overview')->assertOk();
        $this->read($analyticsOnly, 'activity')->assertForbidden();
        $this->read($analyticsOnly, 'last-logins')->assertForbidden();
        $this->read($analyticsOnly, 'acquisition')->assertForbidden();

        $this->grantToRole('admin', [Permissions::USERS_ACTIVITY_VIEW]);
        $this->read($analyticsOnly, 'activity')->assertOk();
        $this->read($analyticsOnly, 'last-logins')->assertOk();
        $this->read($analyticsOnly, 'overview')->assertForbidden();

        $this->grantToRole('admin', [Permissions::USERS_ACQUISITION_VIEW]);
        $this->read($analyticsOnly, 'acquisition')->assertOk();
        $this->read($analyticsOnly, 'activity')->assertForbidden();
    }

    public function test_the_keys_are_manageable_from_access_control(): void
    {
        $root = $this->user('super_admin');
        $adminRole = Role::where('name', 'admin')->firstOrFail();

        // listed under the «المستخدمون» group, with Arabic labels
        $listing = $this->actingAs($root)->getJson('/api/admin/permissions')->assertOk()->json('data');
        $modules = $listing['modules'] ?? $listing;
        $usersGroup = collect($modules)->firstWhere('group', 'users');
        $this->assertNotNull($usersGroup, 'the users group is listed');
        $rows = collect($usersGroup['permissions'])->keyBy('key');
        foreach ([Permissions::USERS_ANALYTICS_VIEW, Permissions::USERS_ACTIVITY_VIEW, Permissions::USERS_ACQUISITION_VIEW] as $key) {
            $this->assertTrue($rows->has($key), "{$key} is listed");
            $this->assertNotEmpty($rows[$key]['label_ar'], "{$key} has an Arabic label");
        }

        // a grant through the role editor endpoint takes effect, a revoke removes it
        $this->actingAs($root)->putJson("/api/admin/roles/{$adminRole->id}/permissions", ['permissions' => [Permissions::USERS_ANALYTICS_VIEW]])->assertOk();
        $admin = $this->user('admin');
        $this->read($admin, 'overview')->assertOk();

        $this->actingAs($root)->putJson("/api/admin/roles/{$adminRole->id}/permissions", ['permissions' => [Permissions::VIEW_USERS]])->assertOk();
        $this->read($admin->fresh(), 'overview')->assertForbidden();
    }

    public function test_the_seeder_is_idempotent_and_touches_only_root_roles(): void
    {
        $before = DB::table('permission_role')->count();
        $this->seed(UsersAnalyticsPermissionSeeder::class);
        $this->seed(UsersAnalyticsPermissionSeeder::class);

        $this->assertSame($before, DB::table('permission_role')->count());
        $this->assertSame(3, Permission::whereIn('name', [Permissions::USERS_ANALYTICS_VIEW, Permissions::USERS_ACTIVITY_VIEW, Permissions::USERS_ACQUISITION_VIEW])->count());
    }

    // ── validation ───────────────────────────────────────────────────────────

    public function test_the_window_is_validated(): void
    {
        $root = $this->user('super_admin');

        $this->read($root, 'overview', ['from' => '2026-11-16', 'to' => '2026-11-10'])->assertStatus(422)->assertJsonValidationErrors('from');
        $this->read($root, 'overview', ['from' => '2026-11-10', 'to' => '2026-11-25'])->assertStatus(422)->assertJsonValidationErrors('to'); // future
        $this->read($root, 'overview', ['from' => '2025-01-01', 'to' => '2026-11-16'])->assertStatus(422)->assertJsonValidationErrors('to');   // > 366 days
        $this->read($root, 'overview', ['from' => '10-11-2026', 'to' => '2026-11-16'])->assertStatus(422)->assertJsonValidationErrors('from');
        $this->read($root, 'overview', ['from' => '2026-11-10'])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->read($root, 'overview', ['from' => '2026-11-10', 'to' => '2026-11-16', 'compare' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors('compare');

        $this->read($root, 'overview', ['from' => '2026-11-16', 'to' => '2026-11-16'])->assertOk(); // a single day is valid
        $this->read($root, 'overview', ['from' => '2025-11-16', 'to' => '2026-11-16'])->assertOk(); // exactly 366 days
    }

    public function test_the_default_window_is_the_last_30_days_in_the_reporting_zone(): void
    {
        $meta = $this->read($this->user('super_admin'), 'overview')->assertOk()->json('data.meta.period');

        $this->assertSame(['from' => '2026-10-22', 'to' => '2026-11-20', 'days' => 30, 'timezone' => 'Europe/Amsterdam'], $meta);
    }

    // ── overview ─────────────────────────────────────────────────────────────

    public function test_overview_kpis_and_comparison(): void
    {
        $root = $this->scenario();

        $d = $this->read($root, 'overview', $this->window(['compare' => 1]))->assertOk()->json('data');

        // the comparison window is the 7 days immediately before the 7-day window
        $this->assertSame('2026-11-03', $d['meta']['previous_period']['from']);
        $this->assertSame('2026-11-09', $d['meta']['previous_period']['to']);
        $this->assertSame(7, $d['meta']['previous_period']['days']);

        $k = $d['kpis'];
        $this->assertSame(['value' => 6, 'cumulative' => true], $k['total_accounts']);          // root, A, B, C, E, F (D is deleted)
        $this->assertSame(2, $k['accounts_created']['value']);                                  // B and C (C is the 13th locally); D deleted
        $this->assertSame(1, $k['accounts_created']['previous']);                               // E
        $this->assertEquals(100.0, $k['accounts_created']['change_pct']);
        $this->assertSame(6, $k['successful_logins']['value']);                                 // failed + out-of-window rows excluded
        $this->assertSame(2, $k['successful_logins']['previous']);
        $this->assertEquals(200.0, $k['successful_logins']['change_pct']);
        $this->assertSame(3, $k['unique_logged_in_users']['value']);                            // A, B, C
        $this->assertSame(2, $k['unique_logged_in_users']['previous']);                         // A, E
        $this->assertEquals(100.0, $k['registration_growth_pct']['value']);
        $this->assertSame(['total_accounts'], $d['meta']['cumulative']);
    }

    public function test_comparison_is_absent_unless_asked_for(): void
    {
        $root = $this->scenario();

        $d = $this->read($root, 'overview', $this->window())->assertOk()->json('data');

        $this->assertNull($d['meta']['previous_period']);
        $this->assertNull($d['kpis']['successful_logins']['previous']);
        $this->assertNull($d['kpis']['successful_logins']['change_pct']);
        $this->assertNull($d['kpis']['registration_growth_pct']['value']);
    }

    public function test_growth_series_follows_local_days_and_running_total(): void
    {
        $root = $this->scenario();

        $series = $this->read($root, 'overview', $this->window())->json('data.charts.accounts_growth');

        $this->assertCount(7, $series);
        $this->assertSame(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15', '2026-11-16'], array_column($series, 'date'));
        $this->assertSame([0, 1, 0, 1, 0, 0, 0], array_column($series, 'created'));            // C created 23:30 UTC = 13th locally
        $this->assertSame([4, 5, 5, 6, 6, 6, 6], array_column($series, 'total'));              // 4 before the window (root, A, E, F)
    }

    public function test_login_trend_counts_events_and_distinct_users_per_local_day(): void
    {
        $root = $this->scenario();

        $trend = $this->read($root, 'overview', $this->window())->json('data.charts.login_trend');
        $byDay = array_column($trend, null, 'date');

        $this->assertSame(['events' => 3, 'users' => 1], ['events' => $byDay['2026-11-10']['events'], 'users' => $byDay['2026-11-10']['users']]); // 23:30Z (10th local) + two in one UTC hour
        $this->assertSame(1, $byDay['2026-11-11']['events']);
        $this->assertSame(1, $byDay['2026-11-12']['events']);
        $this->assertSame(1, $byDay['2026-11-13']['events']);                                   // 23:45Z on the 12th is the 13th locally
        $this->assertSame(0, $byDay['2026-11-16']['events']);                                   // 23:30Z on the 16th is the 17th locally
        $this->assertSame(6, array_sum(array_column($trend, 'events')));
    }

    public function test_new_vs_returning_uses_account_age(): void
    {
        $root = $this->scenario();

        $rows = array_column($this->read($root, 'overview', $this->window())->json('data.charts.new_vs_returning'), null, 'date');

        $this->assertSame(['new' => 0, 'returning' => 1], ['new' => $rows['2026-11-10']['new'], 'returning' => $rows['2026-11-10']['returning']]); // A, account from October
        $this->assertSame(['new' => 1, 'returning' => 0], ['new' => $rows['2026-11-11']['new'], 'returning' => $rows['2026-11-11']['returning']]); // B, created on the 11th
        $this->assertSame(1, $rows['2026-11-12']['returning']);
        $this->assertSame(1, $rows['2026-11-13']['new']);                                       // C
    }

    public function test_role_status_and_country_distributions(): void
    {
        $root = $this->scenario();

        $c = $this->read($root, 'overview', $this->window())->json('data.charts');

        $roles = array_column($c['by_role'], 'count', 'role');
        $this->assertSame(['student' => 3, 'instructor' => 2, 'super_admin' => 1], $roles);

        $this->assertSame(['active' => 5, 'inactive' => 1, 'deleted' => 1, 'verified' => 5, 'unverified' => 1], $c['by_status']);

        $country = $c['by_country'];
        $this->assertEquals(['NL' => 2, 'SA' => 1, 'Yemen' => 1], array_column($country['items'], 'count', 'country'));
        $this->assertSame(2, $country['unknown']);
        $this->assertEquals(66.7, $country['coverage_pct']);
        $this->assertTrue($country['self_reported']);
    }

    public function test_login_history_coverage_is_reported(): void
    {
        $root = $this->scenario();

        $coverage = $this->read($root, 'overview', $this->window())->json('data.meta.login_history');
        $this->assertSame('2026-11-04T10:00:00+00:00', $coverage['first_event_at']);
        $this->assertFalse($coverage['partial']);                                               // history starts before the window

        $wide = $this->read($root, 'overview', ['from' => '2026-10-01', 'to' => '2026-11-16'])->json('data.meta.login_history');
        $this->assertTrue($wide['partial']);                                                    // the window starts before the first recorded login

        AuditLog::query()->where('action', 'LOGIN')->delete();
        $this->assertNull($this->read($root, 'overview', $this->window())->json('data.meta.login_history.first_event_at'));
    }

    public function test_an_empty_platform_returns_zeros_not_errors(): void
    {
        $root = $this->user('super_admin');

        foreach (['overview', 'activity', 'acquisition'] as $path) {
            $this->read($root, $path, $this->window(['compare' => 1]))->assertOk()->assertJsonPath('success', true);
        }
        $k = $this->read($root, 'overview', $this->window())->json('data.kpis');
        $this->assertSame(0, $k['successful_logins']['value']);
        $this->assertSame(0, $k['unique_logged_in_users']['value']);
    }

    // ── activity ─────────────────────────────────────────────────────────────

    public function test_activity_kpis(): void
    {
        $root = $this->scenario();

        $d = $this->read($root, 'activity', $this->window(['compare' => 1]))->assertOk()->json('data');
        $k = $d['kpis'];

        $this->assertSame(6, $k['login_events']['value']);
        $this->assertSame(3, $k['unique_logged_in_users']['value']);
        $this->assertSame(1, $k['returning_users']['value']);                                   // A (account older than the window)
        $this->assertSame(2, $k['new_users_logged_in']['value']);                               // B, C
        $this->assertSame(1, $k['users_back_on_another_day']['value']);                         // only A came on two different days
        $this->assertEquals(33.3, $k['users_back_on_another_day']['share_pct']);
        $this->assertSame(2, $k['login_events']['previous']);
        $this->assertSame(['sessions_without_new_login', 'failed_logins'], $d['meta']['excludes']);
    }

    public function test_heatmap_is_weekday_by_hour_in_the_reporting_zone(): void
    {
        $root = $this->scenario();

        $m = $this->read($root, 'activity', $this->window())->json('data.charts.heatmap');

        $this->assertSame('Europe/Amsterdam', $m['timezone']);
        $matrix = $m['weekday_hour'];
        $this->assertCount(7, $matrix);
        $this->assertCount(24, $matrix[0]);
        $this->assertSame(1, $matrix[1][0]);    // Tuesday 00:30 local (23:30 UTC on Monday)
        $this->assertSame(2, $matrix[1][10]);   // Tuesday 10:00 local, two logins in the same UTC hour
        $this->assertSame(1, $matrix[2][12]);   // Wednesday 12:00
        $this->assertSame(1, $matrix[3][9]);    // Thursday 09:00
        $this->assertSame(1, $matrix[4][0]);    // Friday 00:45 local (23:45 UTC on Thursday)
        $this->assertSame(6, array_sum(array_map('array_sum', $matrix)));
    }

    public function test_return_frequency_and_role_usage(): void
    {
        $root = $this->scenario();

        $c = $this->read($root, 'activity', $this->window())->json('data.charts');

        $this->assertSame(['1' => 2, '2-3' => 1, '4-7' => 0, '8+' => 0], array_column($c['return_frequency'], 'users', 'label'));
        $roles = array_column($c['by_role'], null, 'role');
        $this->assertSame(['events' => 5, 'users' => 2], ['events' => $roles['student']['events'], 'users' => $roles['student']['users']]);
        $this->assertSame(['events' => 1, 'users' => 1], ['events' => $roles['instructor']['events'], 'users' => $roles['instructor']['users']]);
    }

    public function test_inactivity_buckets_are_cumulative_and_use_last_login_only_for_recency(): void
    {
        $root = $this->scenario();

        $d = $this->read($root, 'activity', $this->window())->json('data');

        $this->assertSame([30 => 3, 60 => 2, 90 => 1], array_column($d['inactivity'], 'users', 'days'));   // B 40d, C 70d, E 100d (the disabled account is not counted)
        $this->assertSame(1, $d['never_logged_in']);                                                       // the root account itself
        $this->assertSame(['inactivity', 'never_logged_in'], $d['meta']['cumulative']);

        // the buckets do not move with the window
        $other = $this->read($root, 'activity', ['from' => '2026-11-01', 'to' => '2026-11-02'])->json('data');
        $this->assertSame($d['inactivity'], $other['inactivity']);
    }

    public function test_login_counts_do_not_come_from_last_login_at(): void
    {
        $root = $this->user('super_admin');
        $u = $this->user('student', '2026-10-01 10:00:00', ['last_login_at' => '2026-11-12 10:00:00']); // a recent last login, but NO event rows

        $k = $this->read($root, 'activity', $this->window())->json('data.kpis');

        $this->assertSame(0, $k['login_events']['value']);
        $this->assertSame(0, $k['unique_logged_in_users']['value']);
        $this->assertNotNull($u->fresh()->last_login_at);
    }

    public function test_last_logins_is_paginated_and_carries_no_contact_data(): void
    {
        $root = $this->scenario();

        $r = $this->read($root, 'last-logins', ['per_page' => 2])->assertOk();

        $this->assertCount(2, $r->json('data'));
        $this->assertSame(['id', 'name', 'role', 'is_active', 'last_login_at'], array_keys($r->json('data.0')));
        $this->assertSame(4, $r->json('meta.total'));                                           // A, B, C, E have a last login
        $this->assertSame(2, $r->json('meta.last_page'));
        $this->assertTrue($r->json('data.0.last_login_at') > $r->json('data.1.last_login_at'));  // most recent first

        $this->read($root, 'last-logins', ['per_page' => 51])->assertStatus(422);
        $this->read($root, 'last-logins', ['page' => 0])->assertStatus(422);
    }

    // ── acquisition ──────────────────────────────────────────────────────────

    private function acquisitionScenario(): User
    {
        $root = $this->user('super_admin');
        $this->user('student', '2026-11-11 10:00:00', ['how_did_you_hear_about_us' => 'Instagram']);
        $this->user('student', '2026-11-12 10:00:00', ['how_did_you_hear_about_us' => ' instagram ']);
        $this->user('student', '2026-11-14 10:00:00', ['how_did_you_hear_about_us' => 'LinkedIn']);
        $this->user('student', '2026-11-14 11:00:00', ['how_did_you_hear_about_us' => null]);
        $this->user('student', '2026-11-15 11:00:00', ['how_did_you_hear_about_us' => '   ']);
        $this->user('student', '2026-11-05 10:00:00', ['how_did_you_hear_about_us' => 'instagram']);   // previous window

        foreach ([
            ['pageview', 'newsletter', 'email', 'nov', 'v1', '2026-11-11 10:00:00'],
            ['pageview', 'newsletter', 'email', 'nov', 'v1', '2026-11-11 10:05:00'],
            ['pageview', 'newsletter', 'email', 'nov', 'v2', '2026-11-12 10:00:00'],
            ['pageview', 'facebook', 'paid', 'launch', 'v3', '2026-11-13 10:00:00'],
            ['pageview', null, null, null, 'v4', '2026-11-13 11:00:00'],
            ['pageview', null, null, null, 'v5', '2026-11-13 12:00:00'],
            ['pageview', null, null, null, 'v6', '2026-11-14 12:00:00'],
            ['event', 'newsletter', 'email', 'nov', 'v7', '2026-11-14 12:00:00'],          // not a page view
            ['pageview', 'newsletter', 'email', 'nov', 'v8', '2026-10-01 10:00:00'],        // outside the window
        ] as [$type, $source, $medium, $campaign, $visitor, $at]) {
            DB::table('analytics_events')->insert([
                'type' => $type, 'utm_source' => $source, 'utm_medium' => $medium, 'utm_campaign' => $campaign,
                'visitor_id' => $visitor, 'created_at' => $at,
            ]);
        }

        return $root;
    }

    public function test_self_reported_sources_are_normalised_and_unknown_is_never_direct(): void
    {
        $root = $this->acquisitionScenario();

        $s = $this->read($root, 'acquisition', $this->window(['compare' => 1]))->assertOk()->json('data.self_reported');

        $this->assertSame(5, $s['total_accounts_created']);
        $this->assertSame(['instagram' => 2, 'linkedin' => 1], array_column($s['items'], 'count', 'source'));
        $this->assertSame(2, $s['unknown']);                                                    // null and blank: «unknown», not «direct»
        $this->assertEquals(60.0, $s['coverage_pct']);
        $this->assertTrue($s['free_text']);

        $insta = array_column($s['items'], null, 'source')['instagram'];
        $this->assertSame(1, $insta['previous']);
        $this->assertEquals(100.0, $insta['change_pct']);

        $series = array_column($s['daily'], 'series', 'source')['instagram'];
        $this->assertCount(7, $series);
        $this->assertSame(2, array_sum(array_column($series, 'count')));
    }

    public function test_utm_figures_are_visits_kept_apart_from_accounts(): void
    {
        $root = $this->acquisitionScenario();

        $u = $this->read($root, 'acquisition', $this->window())->json('data.utm_visits');

        $this->assertSame('visits', $u['unit']);
        $this->assertTrue($u['consent_gated']);
        $this->assertFalse($u['linked_to_accounts']);
        $this->assertSame(7, $u['total_pageviews']);                                            // the event row and the out-of-window row are excluded
        $this->assertSame(4, $u['with_utm']);
        $this->assertSame(['newsletter' => 3, 'facebook' => 1], array_column($u['by_source'], 'visits', 'name'));
        $this->assertSame(2, array_column($u['by_source'], null, 'name')['newsletter']['visitors']);   // v1 twice counts once
        $this->assertSame(['email' => 3, 'paid' => 1], array_column($u['by_medium'], 'visits', 'name'));
        $this->assertSame(['nov' => 3, 'launch' => 1], array_column($u['by_campaign'], 'visits', 'name'));
    }

    public function test_unavailable_metrics_are_declared_with_a_reason_and_no_conversion_rate_exists(): void
    {
        $root = $this->acquisitionScenario();

        $d = $this->read($root, 'acquisition', $this->window())->json('data');

        foreach (['referral_codes', 'direct_registration', 'conversion_rate', 'utm_to_account'] as $key) {
            $this->assertFalse($d['unavailable'][$key]['available'], $key);
            $this->assertNotEmpty($d['unavailable'][$key]['reason'], $key);
        }
        $this->assertStringNotContainsString('conversion_rate', json_encode(array_diff_key($d, ['unavailable' => 1])), 'no computed conversion figure');
    }

    // ── safety ───────────────────────────────────────────────────────────────

    public function test_no_response_contains_contact_data(): void
    {
        $root = $this->scenario();
        $emails = User::withTrashed()->pluck('email')->all();

        foreach (['overview', 'activity', 'acquisition', 'last-logins'] as $path) {
            $body = $this->read($root, $path, $this->window(['compare' => 1]))->assertOk()->getContent();
            foreach ($emails as $email) {
                $this->assertStringNotContainsString($email, $body, "{$path} leaks an email");
            }
            $this->assertDoesNotMatchRegularExpression('/"(email|phone|ip_address|user_agent)"/', $body, "{$path} exposes a contact field");
        }
    }

    public function test_reading_analytics_writes_no_login_events(): void
    {
        $root = $this->scenario();
        $before = AuditLog::count();

        foreach (['overview', 'activity', 'acquisition', 'last-logins'] as $path) {
            $this->read($root, $path, $this->window())->assertOk();
        }

        $this->assertSame($before, AuditLog::count());
    }

    public function test_one_real_login_writes_exactly_one_event_and_is_counted_once(): void
    {
        CarbonImmutable::setTestNow('2026-11-12 08:00:00');
        \Illuminate\Support\Carbon::setTestNow('2026-11-12 08:00:00');
        $student = $this->user('student', '2026-10-01 10:00:00');
        $student->forceFill(['email_verified_at' => '2026-10-01 10:00:00'])->save();

        $this->postJson('/api/auth/login', ['email' => $student->email, 'password' => 'password'])->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'LOGIN')->where('user_id', $student->id)->count());

        CarbonImmutable::setTestNow('2026-11-20 12:00:00');
        \Illuminate\Support\Carbon::setTestNow('2026-11-20 12:00:00');
        $k = $this->read($this->user('super_admin'), 'activity', $this->window())->json('data.kpis');
        $this->assertSame(1, $k['login_events']['value']);
        $this->assertSame(1, $k['unique_logged_in_users']['value']);

        // a failed attempt is recorded under another action and never counted
        $this->postJson('/api/auth/login', ['email' => $student->email, 'password' => 'wrong'])->assertStatus(422);
        $k = $this->read($this->user('super_admin'), 'activity', $this->window())->json('data.kpis');
        $this->assertSame(1, $k['login_events']['value']);
    }

    // ── daylight-saving time ─────────────────────────────────────────────────

    public function test_days_follow_the_zones_offset_across_the_autumn_change(): void
    {
        // Amsterdam leaves summer time on 2026-10-25 at 01:00 UTC: +02:00 before, +01:00 after.
        $root = $this->user('super_admin');
        $u = $this->user('student', '2026-09-01 08:00:00');
        $this->login($u, '2026-10-24 22:30:00');   // +2 → 25th 00:30
        $this->login($u, '2026-10-25 22:30:00');   // +1 → 25th 23:30 (still the 25th)
        $this->login($u, '2026-10-25 23:30:00');   // +1 → 26th 00:30

        $trend = array_column($this->read($root, 'overview', ['from' => '2026-10-24', 'to' => '2026-10-26'])->json('data.charts.login_trend'), 'events', 'date');

        $this->assertSame(['2026-10-24' => 0, '2026-10-25' => 2, '2026-10-26' => 1], $trend);
    }

    public function test_the_spring_change_does_not_skew_the_day_count_or_the_comparison_window(): void
    {
        // Amsterdam enters summer time on 2026-03-29 (a 23-hour day).
        $root = $this->user('super_admin');

        $meta = $this->read($root, 'overview', ['from' => '2026-03-28', 'to' => '2026-03-30', 'compare' => 1])->assertOk()->json('data.meta');

        $this->assertSame(3, $meta['period']['days']);
        $this->assertSame(['from' => '2026-03-25', 'to' => '2026-03-27', 'days' => 3], \Illuminate\Support\Arr::only($meta['previous_period'], ['from', 'to', 'days']));

        $growth = $this->read($root, 'overview', ['from' => '2026-03-28', 'to' => '2026-03-30'])->json('data.charts.accounts_growth');
        $this->assertSame(['2026-03-28', '2026-03-29', '2026-03-30'], array_column($growth, 'date'));
    }

    // ── cache, rate limit, query count ───────────────────────────────────────

    public function test_responses_are_cached_briefly_and_keyed_by_window(): void
    {
        config(['users-analytics.cache_ttl' => 300]);
        Cache::flush();
        $root = $this->scenario();

        $first = $this->read($root, 'overview', $this->window())->json('data.kpis.total_accounts.value');
        $this->user('student');                                                                 // one more account
        $cached = $this->read($root, 'overview', $this->window())->json('data.kpis.total_accounts.value');
        $otherWindow = $this->read($root, 'overview', ['from' => '2026-11-11', 'to' => '2026-11-16'])->json('data.kpis.total_accounts.value');

        $this->assertSame($first, $cached, 'the same window is served from the cache');
        $this->assertSame($first + 1, $otherWindow, 'a different window is computed fresh');

        Cache::flush();
        $this->assertSame($first + 1, $this->read($root, 'overview', $this->window())->json('data.kpis.total_accounts.value'));
    }

    public function test_requests_are_rate_limited_per_user(): void
    {
        config(['users-analytics.rate_limit_per_minute' => 3]);
        $root = $this->user('super_admin');
        $other = $this->user('tech_admin');

        for ($i = 0; $i < 3; $i++) {
            $this->read($root, 'overview')->assertOk();
        }
        $this->read($root, 'overview')->assertStatus(429);
        $this->read($other, 'overview')->assertOk();                                             // another user has their own budget
    }

    public function test_query_count_does_not_grow_with_the_data(): void
    {
        $root = $this->user('super_admin');
        $measure = function (string $path) use ($root): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->read($root, $path, $this->window(['compare' => 1]))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $populate = function (int $from, int $to): void {
            for ($i = $from; $i < $to; $i++) {
                $u = $this->user('student', '2026-11-11 10:00:00', ['how_did_you_hear_about_us' => 'src'.($i % 7), 'country_code' => ['NL', 'SA', 'YE'][$i % 3]]);
                $this->login($u, '2026-11-1'.($i % 6).' 10:00:00');
                $this->login($u, '2026-11-05 10:00:00');
            }
        };
        $all = fn () => ['overview' => $measure('overview'), 'activity' => $measure('activity'), 'acquisition' => $measure('acquisition')];

        // an empty window skips the follow-up queries (nothing to chunk), so compare two NON-empty sizes
        $populate(0, 40);
        $medium = $all();
        $populate(40, 120);
        $large = $all();

        $this->assertSame($medium, $large, 'queries are aggregates; none runs per user (no N+1)');
        $this->assertLessThan(40, max($large));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Replace the role's permissions with exactly these (what the Access Control role editor does). */
    private function grantToRole(string $role, array $permissions): void
    {
        Role::where('name', $role)->firstOrFail()->syncPermissions($permissions);
        Cache::flush();
    }
}
