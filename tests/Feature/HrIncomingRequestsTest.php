<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\HrRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\VolunteerOpportunity;
use App\Models\VolunteerRequest;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HR incoming (staffing) requests: list/details/status, smart volunteer matching and draft
 * vacancy opportunities.
 *
 * The matching is decision support and the draft is only a draft: no volunteer is contacted or
 * assigned, nothing is published, and unauthorized users get nothing at every step.
 */
class HrIncomingRequestsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/admin/hr-requests';

    private Department $marketing;
    private Department $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->marketing = Department::create(['name' => 'Marketing', 'name_ar' => 'التسويق والإعلام', 'slug' => 'marketing', 'status' => 'active', 'is_active' => true]);
        $this->finance = Department::create(['name' => 'Finance', 'name_ar' => 'المالية', 'slug' => 'finance', 'status' => 'active', 'is_active' => true]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function as(string|User $who)
    {
        Sanctum::actingAs(is_string($who) ? $this->user($who) : $who);

        return $this;
    }

    private function request(array $over = []): HrRequest
    {
        return HrRequest::forceCreate(array_merge([
            'department_id' => $this->marketing->id,
            'request_type' => 'مصمم محتوى',
            'required_count' => 2,
            'skills_and_specs' => "تصميم جرافيك، صناعة المحتوى، مونتاج الفيديو",
            'notes' => "تصميم منشورات المنصة\nدعم الحملات الإعلامية",
            'status' => 'pending',
            'target_audience' => 'hr',
            'created_by' => $this->user('department_manager')->id,
        ], $over));
    }

    private function volunteer(array $over = []): VolunteerRequest
    {
        return VolunteerRequest::forceCreate(array_merge([
            'full_name' => 'أحمد محمد', 'email' => 'secret.person@example.com', 'phone' => '+31600000000', 'country' => 'NL', 'city' => 'Amsterdam', 'gender' => 'male',
            'desired_department' => 'التسويق', 'experience_level' => 'متوسط', 'skills' => 'تصميم جرافيك، صناعة المحتوى', 'availability' => '10 ساعات أسبوعيًا',
            'motivation' => 'SECRET-MOTIVATION أرغب في المساهمة بخبرتي في المنصة.', 'previous_experience' => 'إدارة حسابات التواصل الاجتماعي لجمعية',
            'cv_file_path' => 'secret/cv.pdf', 'notes' => 'SECRET-NOTES', 'admin_notes' => 'SECRET-ADMIN-NOTES', 'status' => 'pending',
        ], $over));
    }

    private function runMatch(HrRequest $r, array $q = [])
    {
        return $this->getJson(self::BASE.'/'.$r->id.'/volunteer-matches'.($q ? '?'.http_build_query($q) : ''));
    }

    // ═══ List, details, status ═══════════════════════════════════════════════

    public function test_an_authorized_hr_user_lists_requests_in_a_safe_consistent_shape(): void
    {
        $r = $this->request();

        $json = $this->as('hr_manager')->getJson(self::BASE.'/incoming')->assertOk()->json();

        $this->assertCount(1, $json['data']);
        $row = $json['data'][0];
        $this->assertSame(['id', 'title', 'request_type', 'department', 'requester', 'required_count', 'skills_and_specs', 'skills', 'notes', 'status', 'status_label', 'target_audience', 'target_audience_label', 'hr_feedback', 'created_at', 'updated_at', 'draft'], array_keys($row));
        $this->assertSame($r->id, $row['id']);
        $this->assertSame('مصمم محتوى — التسويق والإعلام', $row['title']);
        $this->assertSame(['تصميم جرافيك', 'صناعة المحتوى', 'مونتاج الفيديو'], $row['skills']);
        $this->assertSame('جديد', $row['status_label']);
        $this->assertSame(['id', 'name'], array_keys($row['requester']), 'name only: no email or phone');
        $this->assertNull($row['draft']);
        $this->assertSame(1, $json['stats']['total']);
        $this->assertSame(1, $json['stats']['pending']);
        $this->assertTrue($json['capabilities']['can_manage']);
        $this->assertTrue($json['capabilities']['can_match_volunteers']);
        $this->assertStringNotContainsString('@', json_encode($json['data']));
    }

    public function test_unauthorized_users_cannot_list_or_read_requests(): void
    {
        $r = $this->request();

        $this->getJson(self::BASE.'/incoming')->assertUnauthorized();
        foreach (['student', 'instructor', 'volunteer', 'finance_manager', 'support_agent', 'department_manager'] as $role) {
            $this->as($role)->getJson(self::BASE.'/incoming')->assertForbidden();
            $this->getJson(self::BASE.'/'.$r->id)->assertForbidden();
        }
    }

    public function test_executive_and_admin_roles_see_everything_but_only_manage_team_holders_can_act(): void
    {
        $r = $this->request();

        $exec = $this->as('executive_admin')->getJson(self::BASE.'/incoming')->assertOk()->json();
        $this->assertCount(1, $exec['data']);
        $this->assertFalse($exec['capabilities']['can_manage'], 'executive access is read-only');
        $this->assertFalse($exec['capabilities']['can_match_volunteers']);

        foreach (['admin', 'super_admin', 'tech_admin', 'hr_manager'] as $role) {
            $caps = $this->as($role)->getJson(self::BASE.'/incoming')->assertOk()->json('capabilities');
            $this->assertTrue($caps['can_manage'], $role);
        }
    }

    public function test_a_department_leader_sees_only_the_departments_they_lead(): void
    {
        $mine = $this->request(['department_id' => $this->marketing->id]);
        $other = $this->request(['department_id' => $this->finance->id]);
        $leader = $this->user('department_manager');
        $this->marketing->forceFill(['leader_id' => $leader->id])->save();

        $json = $this->as($leader)->getJson(self::BASE.'/incoming')->assertOk()->json();

        $this->assertSame([$mine->id], array_column($json['data'], 'id'));
        $this->assertFalse($json['capabilities']['can_manage']);
        $this->getJson(self::BASE.'/'.$mine->id)->assertOk();
        $this->getJson(self::BASE.'/'.$other->id)->assertForbidden();
    }

    public function test_filters_search_stats_and_pagination(): void
    {
        $a = $this->request(['status' => 'pending', 'request_type' => 'مصمم محتوى']);
        $b = $this->request(['status' => 'approved', 'department_id' => $this->finance->id, 'request_type' => 'محاسب', 'skills_and_specs' => 'محاسبة']);
        $c = $this->request(['status' => 'in_progress']);
        $b->forceFill(['created_at' => now()->subDays(20)])->save();
        $this->as('hr_manager');

        $this->assertSame([$a->id], array_column($this->getJson(self::BASE.'/incoming?status=pending')->json('data'), 'id'));
        $this->assertSame([$b->id], array_column($this->getJson(self::BASE.'/incoming?department_id='.$this->finance->id)->json('data'), 'id'));
        $this->assertSame([$b->id], array_column($this->getJson(self::BASE.'/incoming?q='.urlencode('محاسب'))->json('data'), 'id'));
        $this->assertSame([$b->id], array_column($this->getJson(self::BASE.'/incoming?date_to='.now()->subDays(10)->toDateString())->json('data'), 'id'));

        $stats = $this->getJson(self::BASE.'/incoming?status=approved')->json('stats');
        $this->assertSame(['total' => 3, 'pending' => 1, 'in_progress' => 1, 'approved' => 1, 'rejected' => 0, 'with_drafts' => 0], $stats, 'cards ignore the status filter');

        $page = $this->getJson(self::BASE.'/incoming?per_page=2')->json();
        $this->assertCount(2, $page['data']);
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 2, 'total' => 3], $page['meta']);

        $this->getJson(self::BASE.'/incoming?status=nonsense')->assertStatus(422);
        $this->getJson(self::BASE.'/incoming?per_page=5000')->assertStatus(422);
    }

    public function test_legacy_rows_with_missing_fields_do_not_crash(): void
    {
        $r = $this->request(['department_id' => null, 'notes' => null, 'request_type' => '', 'skills_and_specs' => '']);

        $row = $this->as('hr_manager')->getJson(self::BASE.'/incoming')->assertOk()->json('data.0');

        $this->assertNull($row['department']);
        $this->assertSame([], $row['skills']);
        $this->assertSame('طلب احتياج متطوعين', $row['title']);
        $this->getJson(self::BASE.'/'.$r->id)->assertOk();
        $this->getJson(self::BASE.'/'.$r->id.'/volunteer-matches')->assertOk()->assertJsonPath('data', []);
    }

    public function test_status_updates_need_manage_team_and_a_valid_status(): void
    {
        $r = $this->request();

        $this->patchJson(self::BASE.'/'.$r->id.'/status', ['status' => 'approved'])->assertUnauthorized();
        foreach (['student', 'executive_admin', 'department_manager'] as $role) {
            $this->as($role)->patchJson(self::BASE.'/'.$r->id.'/status', ['status' => 'approved'])->assertForbidden();
        }
        $this->assertSame('pending', $r->fresh()->status, 'a forbidden update changed nothing');

        $this->as('hr_manager');
        $this->patchJson(self::BASE.'/'.$r->id.'/status', ['status' => 'bogus'])->assertStatus(422)->assertJsonPath('errors.status.0', 'حالة الطلب غير صالحة.');
        $this->patchJson(self::BASE.'/'.$r->id.'/status', ['status' => 'in_progress', 'hr_feedback' => 'قيد المراجعة'])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->assertSame('in_progress', $r->fresh()->status);
    }

    // ═══ Matching ════════════════════════════════════════════════════════════

    public function test_an_authorized_user_runs_matching_and_gets_score_label_skills_and_reasons(): void
    {
        $r = $this->request();
        $v = $this->volunteer();

        $res = $this->as('hr_manager')->runMatch($r)->assertOk()->json();

        $this->assertCount(1, $res['data']);
        $c = $res['data'][0];
        $this->assertSame($v->id, $c['id']);
        $this->assertSame('أحمد محمد', $c['name']);
        $this->assertGreaterThanOrEqual(65, $c['score']);
        $this->assertContains($c['label'], ['مناسب', 'مناسب جدًا']);
        $this->assertSame(['تصميم جرافيك', 'صناعة المحتوى'], $c['matched_skills']);
        $this->assertSame(['مونتاج الفيديو'], $c['missing_skills']);
        $this->assertNotEmpty($c['reasons']);
        $this->assertStringContainsString('يمتلك مهارات مطابقة', implode(' ', $c['reasons']));
        $this->assertContains('اختار نفس مجال القسم المطلوب.', $c['reasons']);
        $this->assertSame('pending', $c['status']);
        $this->assertSame('جديد', $c['status_label']);
        $this->assertSame('/dashboard/hr/volunteer-requests/'.$v->id, $c['url']);
        $this->assertSame('مراجعة المرشح', $c['action_label']);
        $this->assertGreaterThan(0, count($c['breakdown']));
        $this->assertFalse($res['meta']['persisted']);
        $this->assertSame(45, $res['meta']['min_score']);
        $this->assertNotEmpty($res['meta']['limitations']);
    }

    public function test_weak_candidates_are_excluded_by_the_minimum_score_and_can_be_included_by_lowering_it(): void
    {
        $r = $this->request();
        $good = $this->volunteer();
        $weak = $this->volunteer(['full_name' => 'خالد', 'desired_department' => 'المالية', 'skills' => 'محاسبة', 'previous_experience' => 'عمل مصرفي', 'motivation' => 'أرغب في التطوع لخدمة المجتمع والمنصة.']);

        $ids = array_column($this->as('hr_manager')->runMatch($r)->json('data'), 'id');
        $this->assertSame([$good->id], $ids);

        $all = $this->runMatch($r, ['min_score' => 0])->json('data');
        $this->assertEqualsCanonicalizing([$good->id, $weak->id], array_column($all, 'id'));
        $this->assertGreaterThan($all[1]['score'], $all[0]['score'], 'sorted best first');
        $this->assertSame('ضعيف', collect($all)->firstWhere('id', $weak->id)['label']);
    }

    public function test_rejected_and_already_converted_applications_are_never_matched(): void
    {
        $r = $this->request();
        $this->volunteer(['status' => 'rejected']);
        $this->volunteer(['status' => 'converted_to_member']);
        $accepted = $this->volunteer(['status' => 'accepted']);
        $deleted = $this->volunteer();
        $deleted->delete();

        $c = $this->as('hr_manager')->runMatch($r, ['min_score' => 0])->json('data');

        $this->assertSame([$accepted->id], array_column($c, 'id'));
        $this->assertContains('طلبه مقبول مسبقًا ولم يُسنَد بعد.', $c[0]['warnings']);
    }

    public function test_matching_never_exposes_contact_details_files_or_internal_notes(): void
    {
        $r = $this->request();
        $this->volunteer();

        $body = $this->as('hr_manager')->runMatch($r)->getContent();
        $text = json_encode(json_decode($body, true), JSON_UNESCAPED_UNICODE);

        foreach (['secret.person@example.com', '+31600000000', 'secret/cv.pdf', 'SECRET', 'admin_notes', 'motivation', '"email"', '"phone"', 'cv_file_path', 'Amsterdam'] as $needle) {
            $this->assertStringNotContainsString($needle, $text, $needle);
        }
    }

    public function test_no_candidates_returns_an_empty_result_not_an_error(): void
    {
        $r = $this->request();

        $res = $this->as('hr_manager')->runMatch($r)->assertOk()->json();

        $this->assertSame([], $res['data']);
        $this->assertSame(0, $res['meta']['returned']);
    }

    public function test_a_request_without_usable_skills_says_why_and_matches_nobody(): void
    {
        $r = $this->request(['skills_and_specs' => 'و', 'department_id' => null, 'request_type' => '', 'notes' => null]);
        $this->volunteer();

        $res = $this->as('hr_manager')->runMatch($r, ['min_score' => 0])->assertOk()->json();

        $this->assertSame([], $res['data']);
        $this->assertStringContainsString('لا تكفي بيانات الطلب', implode(' ', $res['meta']['limitations']));
    }

    public function test_applications_with_missing_fields_are_scored_without_error(): void
    {
        $r = $this->request();
        $this->volunteer(['skills' => null, 'previous_experience' => null, 'desired_department' => 'التسويق']);

        $this->as('hr_manager')->runMatch($r, ['min_score' => 0])->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_results_are_capped_and_the_evaluation_is_bounded(): void
    {
        $r = $this->request();
        foreach (range(1, 30) as $i) {
            $this->volunteer(['full_name' => "متطوع {$i}"]);
        }

        $res = $this->as('hr_manager')->runMatch($r)->json();

        $this->assertCount(20, $res['data']);
        $this->assertSame(30, $res['meta']['evaluated']);
    }

    public function test_only_users_who_may_manage_and_see_volunteers_can_run_matching(): void
    {
        $r = $this->request();
        $this->volunteer();

        $this->getJson(self::BASE.'/'.$r->id.'/volunteer-matches')->assertUnauthorized();
        foreach (['student', 'instructor', 'finance_manager', 'department_manager', 'executive_admin'] as $role) {
            $res = $this->as($role)->runMatch($r)->assertForbidden();
            $this->assertStringNotContainsString('أحمد', $res->getContent());
        }

        // manage_team without view_volunteers: may run the HR workflow, not read volunteer applications.
        $role = Role::create(['name' => 'staffing_clerk', 'display_name' => 'staffing_clerk', 'is_system' => false]);
        $role->grantPermission('manage_team');
        $clerk = User::factory()->create(['role' => 'staffing_clerk', 'is_active' => true]);
        $this->as($clerk)->getJson(self::BASE.'/incoming')->assertOk();
        $this->runMatch($r)->assertForbidden();

        $role->grantPermission('view_volunteers');
        $this->as($clerk->fresh())->runMatch($r)->assertOk();
    }

    public function test_a_department_leader_cannot_run_matching_even_for_their_own_request(): void
    {
        $r = $this->request();
        $leader = $this->user('department_manager');
        $this->marketing->forceFill(['leader_id' => $leader->id])->save();
        $this->volunteer();

        $this->as($leader)->runMatch($r)->assertForbidden();
    }

    public function test_matching_changes_nothing_and_contacts_nobody(): void
    {
        $r = $this->request();
        $v = $this->volunteer();
        $before = [VolunteerRequest::count(), VolunteerOpportunity::count(), DB::table('notification_logs')->count(), DB::table('platform_notifications')->count(), DB::table('jobs')->count()];

        $this->as('hr_manager')->runMatch($r)->assertOk();

        $this->assertSame($before, [VolunteerRequest::count(), VolunteerOpportunity::count(), DB::table('notification_logs')->count(), DB::table('platform_notifications')->count(), DB::table('jobs')->count()]);
        $this->assertSame('pending', $v->fresh()->status, 'the application was not touched');
        $this->assertSame('pending', $r->fresh()->status, 'the request was not touched');
    }

    // ═══ Draft opportunity ═══════════════════════════════════════════════════

    public function test_the_suggestion_is_prefilled_from_the_request_and_saves_nothing(): void
    {
        $r = $this->request();

        $d = $this->as('hr_manager')->getJson(self::BASE.'/'.$r->id.'/opportunity-draft/suggestion')->assertOk()->json('data');

        $this->assertSame('فرصة تطوعية: مصمم محتوى لإدارة التسويق والإعلام', $d['title']);
        $this->assertStringContainsString('التسويق والإعلام', $d['summary']);
        $this->assertSame(['تصميم جرافيك', 'صناعة المحتوى', 'مونتاج الفيديو'], $d['required_skills']);
        $this->assertSame(['تصميم منشورات المنصة', 'دعم الحملات الإعلامية'], $d['responsibilities']);
        $this->assertSame(2, $d['volunteers_needed']);
        $this->assertSame($this->marketing->id, $d['department_id']);
        $this->assertNotEmpty($d['application_instructions']);
        $this->assertSame(0, VolunteerOpportunity::count());
    }

    public function test_creating_a_draft_stores_it_as_an_unpublished_draft_linked_to_the_request(): void
    {
        $r = $this->request();
        $hr = $this->user('hr_manager');
        $payload = $this->as($hr)->getJson(self::BASE.'/'.$r->id.'/opportunity-draft/suggestion')->json('data');
        $payload['title'] = 'فرصة تطوعية: مصمم محتوى (معدّل)';
        $payload['availability'] = '8 ساعات أسبوعيًا';
        $payload['location_mode'] = 'remote';
        $payload['required_skills'] = ['تصميم جرافيك', '', '  ', 'مونتاج'];

        $res = $this->postJson(self::BASE.'/'.$r->id.'/opportunity-draft', $payload)->assertCreated()->json('data');

        $this->assertSame('draft', $res['status']);
        $this->assertFalse($res['is_published']);
        $this->assertSame($r->id, $res['incoming_request_id']);
        $this->assertSame('فرصة تطوعية: مصمم محتوى (معدّل)', $res['title']);
        $this->assertSame(['تصميم جرافيك', 'مونتاج'], $res['required_skills'], 'blank entries removed');
        $row = VolunteerOpportunity::first();
        $this->assertSame('draft', $row->status);
        $this->assertNull($row->published_at);
        $this->assertSame($hr->id, $row->created_by);
        $this->assertSame($row->id, $r->fresh()->opportunityDraft->id);

        $list = $this->getJson(self::BASE.'/incoming')->json();
        $this->assertSame(['id' => $row->id, 'title' => $res['title'], 'status' => 'draft', 'status_label' => 'مسودة', 'applicants_count' => 0], $list['data'][0]['draft']);
        $this->assertSame(1, $list['stats']['with_drafts']);
        $this->assertSame(1, $this->getJson(self::BASE.'/incoming?has_draft=yes')->json('meta.total'));
        $this->assertSame(0, $this->getJson(self::BASE.'/incoming?has_draft=no')->json('meta.total'));
    }

    public function test_a_payload_cannot_publish_or_re_link_the_draft(): void
    {
        $r = $this->request();
        $other = $this->request();
        $payload = $this->as('hr_manager')->getJson(self::BASE.'/'.$r->id.'/opportunity-draft/suggestion')->json('data');

        $this->postJson(self::BASE.'/'.$r->id.'/opportunity-draft', $payload + ['status' => 'published', 'published_at' => now()->toDateTimeString(), 'incoming_request_id' => $other->id, 'created_by' => 999])->assertCreated();

        $row = VolunteerOpportunity::first();
        $this->assertSame('draft', $row->status);
        $this->assertNull($row->published_at);
        $this->assertSame($r->id, $row->incoming_request_id);
        $this->assertNotSame(999, $row->created_by);
    }

    public function test_only_one_draft_per_request_and_it_can_be_edited(): void
    {
        $r = $this->request();
        $payload = $this->as('hr_manager')->getJson(self::BASE.'/'.$r->id.'/opportunity-draft/suggestion')->json('data');
        $this->postJson(self::BASE.'/'.$r->id.'/opportunity-draft', $payload)->assertCreated();

        $dup = $this->postJson(self::BASE.'/'.$r->id.'/opportunity-draft', $payload)->assertStatus(409);
        $this->assertStringContainsString('توجد مسودة', $dup->json('message'));
        $this->assertSame(1, VolunteerOpportunity::count());

        $payload['title'] = 'عنوان محدّث للفرصة';
        $this->putJson(self::BASE.'/'.$r->id.'/opportunity-draft', $payload)->assertOk()->assertJsonPath('data.title', 'عنوان محدّث للفرصة');
        $this->assertSame(1, VolunteerOpportunity::count());

        $sugg = $this->getJson(self::BASE.'/'.$r->id.'/opportunity-draft/suggestion')->json();
        $this->assertSame('عنوان محدّث للفرصة', $sugg['existing_draft']['title']);
    }

    public function test_the_database_itself_refuses_a_second_draft_for_one_request(): void
    {
        $r = $this->request();
        VolunteerOpportunity::create(['incoming_request_id' => $r->id, 'title' => 'a']);

        $this->expectException(QueryException::class);
        VolunteerOpportunity::create(['incoming_request_id' => $r->id, 'title' => 'b']);
    }

    public function test_unauthorized_users_cannot_create_or_edit_drafts(): void
    {
        $r = $this->request();
        $payload = ['title' => 'عنوان الفرصة', 'summary' => 'وصف مختصر للفرصة التطوعية'];

        $this->postJson(self::BASE.'/'.$r->id.'/opportunity-draft', $payload)->assertUnauthorized();
        $leader = $this->user('department_manager');
        $this->marketing->forceFill(['leader_id' => $leader->id])->save();
        foreach (['student', 'executive_admin', $leader] as $who) {
            $this->as($who);
            $this->getJson(self::BASE.'/'.$r->id.'/opportunity-draft/suggestion')->assertForbidden();
            $this->postJson(self::BASE.'/'.$r->id.'/opportunity-draft', $payload)->assertForbidden();
            $this->putJson(self::BASE.'/'.$r->id.'/opportunity-draft', $payload)->assertForbidden();
        }
        $this->assertSame(0, VolunteerOpportunity::count());
    }

    public function test_draft_validation_errors_are_clear_arabic_messages(): void
    {
        $r = $this->request();
        $this->as('hr_manager');

        $res = $this->postJson(self::BASE.'/'.$r->id.'/opportunity-draft', [])->assertStatus(422);
        $this->assertSame('عنوان الفرصة مطلوب.', $res->json('errors.title.0'));
        $this->assertSame('الوصف المختصر مطلوب.', $res->json('errors.summary.0'));

        $bad = $this->postJson(self::BASE.'/'.$r->id.'/opportunity-draft', ['title' => 'عنوان الفرصة', 'summary' => 'وصف مختصر للفرصة', 'volunteers_needed' => 0, 'location_mode' => 'moon', 'start_date' => 'غير تاريخ'])->assertStatus(422);
        $this->assertSame('العدد المطلوب يجب ألا يقل عن 1.', $bad->json('errors.volunteers_needed.0'));
        $this->assertSame('نمط المكان غير صالح.', $bad->json('errors.location_mode.0'));
        $this->assertSame('تاريخ البدء غير صالح.', $bad->json('errors.start_date.0'));
        $this->assertSame(0, VolunteerOpportunity::count());
    }

    public function test_there_is_no_way_to_publish_assign_or_invite(): void
    {
        $r = $this->request();
        $v = $this->volunteer();
        $this->as('super_admin');

        foreach (['publish', 'opportunity-draft/publish', 'assign', 'invite', 'assign-volunteer'] as $path) {
            $this->postJson(self::BASE.'/'.$r->id.'/'.$path, ['volunteer_id' => $v->id])->assertStatus(404);
        }
        $this->getJson('/api/opportunities')->assertNotFound();
        $this->getJson('/api/public/opportunities')->assertNotFound();
    }
}
