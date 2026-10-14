<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\HrRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\VolunteerOpportunity;
use App\Models\VolunteerRequest;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\RoleDefaultMap;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 4 (public volunteer opportunities and the publish workflow) and Phase 5 (applications
 * linked to the originating incoming request).
 *
 * The chain under test: incoming request -> opportunity (draft -> published -> closed) ->
 * application -> volunteer. Drafts never leak, only an authorized manager publishes, the link to
 * the request comes from stored keys, and an application never changes the request.
 */
class VolunteerOpportunitiesTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC = '/api/volunteer-opportunities';
    private const ADMIN = '/api/admin/volunteer-opportunities';

    private Department $marketing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->marketing = Department::create(['name' => 'Marketing', 'name_ar' => 'التسويق والإعلام', 'slug' => 'marketing', 'status' => 'active', 'is_active' => true]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function as(string|User $who): static
    {
        Sanctum::actingAs(is_string($who) ? $this->user($who) : $who);

        return $this;
    }

    private function incoming(array $over = []): HrRequest
    {
        return HrRequest::forceCreate(array_merge([
            'department_id' => $this->marketing->id, 'request_type' => 'مصمم محتوى', 'required_count' => 2,
            'skills_and_specs' => 'تصميم جرافيك، صناعة المحتوى', 'notes' => 'دعم الحملات', 'status' => 'pending', 'target_audience' => 'hr',
            'created_by' => $this->user('department_manager')->id,
        ], $over));
    }

    /** A complete opportunity; pass 'status' to place it anywhere in the lifecycle. */
    private function opportunity(array $over = []): VolunteerOpportunity
    {
        $status = $over['status'] ?? 'published';
        unset($over['status']);

        $o = VolunteerOpportunity::create(array_merge([
            'department_id' => $this->marketing->id,
            'title' => 'فرصة تطوعية: مصمم محتوى',
            'summary' => 'تبحث إدارة التسويق والإعلام عن متطوعين للمساهمة في تصميم المحتوى.',
            'responsibilities' => ['تصميم منشورات المنصة'],
            'required_skills' => ['تصميم جرافيك', 'صناعة المحتوى'],
            'preferred_skills' => ['مونتاج'],
            'volunteers_needed' => 2, 'availability' => '8 ساعات أسبوعيًا', 'duration' => '3 أشهر', 'location_mode' => 'remote',
            'application_instructions' => 'قدّم عبر المنصة.',
        ], $over));

        $o->forceFill([
            'status' => $status,
            'published_at' => in_array($status, ['published', 'closed', 'archived'], true) ? now()->subDay() : null,
            'closed_at' => $status === 'closed' ? now() : null,
        ])->save();

        return $o->fresh();
    }

    private function applyPayload(array $over = []): array
    {
        return array_merge([
            'full_name' => 'سارة الأحمد', 'email' => 'Sara.Ahmad@Example.com', 'phone' => '+31600000000', 'country' => 'هولندا', 'city' => 'أمستردام', 'gender' => 'female',
            'experience_level' => 'متوسط', 'availability' => '5-10 ساعات أسبوعيًا', 'skills' => 'تصميم جرافيك، صناعة المحتوى',
            'previous_experience' => 'تصميم هوية لجمعية', 'motivation' => 'أرغب في المساهمة بخبرتي في تصميم محتوى المنصة.', 'agree_terms' => true,
        ], $over);
    }

    private function apply(VolunteerOpportunity $o, array $over = [])
    {
        return $this->postJson(self::PUBLIC.'/'.$o->id.'/applications', $this->applyPayload($over));
    }

    private function ids($response): array
    {
        return array_column($response->json('data'), 'id');
    }

    // ═══ Phase 4 — public listing ════════════════════════════════════════════

    public function test_only_published_open_opportunities_are_listed_publicly(): void
    {
        $open = $this->opportunity();
        $future = $this->opportunity(['title' => 'فرصة بموعد قادم', 'closes_at' => now()->addDays(10)->toDateString()]);
        $today = $this->opportunity(['title' => 'آخر يوم', 'closes_at' => now()->toDateString()]);
        $this->opportunity(['status' => 'draft', 'title' => 'مسودة']);
        $this->opportunity(['status' => 'closed', 'title' => 'مغلقة']);
        $this->opportunity(['status' => 'archived', 'title' => 'مؤرشفة']);
        $this->opportunity(['title' => 'منتهية', 'closes_at' => now()->subDay()->toDateString()]);

        $res = $this->getJson(self::PUBLIC)->assertOk();

        $this->assertEqualsCanonicalizing([$open->id, $future->id, $today->id], $this->ids($res));
        $this->assertSame(3, $res->json('meta.total'));
    }

    public function test_the_listing_needs_no_authentication_and_exposes_only_presentation_fields(): void
    {
        $o = $this->opportunity(['incoming_request_id' => $this->incoming()->id]);
        $this->user('hr_manager');

        $row = $this->getJson(self::PUBLIC)->assertOk()->json('data.0');

        $this->assertSame(['id', 'title', 'summary', 'field', 'department_id', 'location_mode', 'location_label', 'commitment', 'duration', 'volunteers_needed', 'closes_at', 'days_left', 'published_at', 'status', 'status_label'], array_keys($row));
        $this->assertSame($o->id, $row['id']);
        $this->assertSame('التسويق والإعلام', $row['field']);
        $this->assertSame('عن بُعد', $row['location_label']);
        $this->assertSame('8 ساعات أسبوعيًا', $row['commitment']);
        $this->assertSame('open', $row['status']);
        $text = json_encode($this->getJson(self::PUBLIC)->json(), JSON_UNESCAPED_UNICODE);
        foreach (['incoming_request', 'created_by', 'updated_by', 'published_by', 'applicants', 'applications_count', 'hr_feedback', 'responsibilities'] as $needle) {
            $this->assertStringNotContainsString($needle, $text, $needle);
        }
    }

    public function test_a_deadline_within_a_week_is_marked_closing_soon(): void
    {
        $this->opportunity(['title' => 'قريبة', 'closes_at' => now()->addDays(3)->toDateString()]);
        $this->opportunity(['title' => 'بعيدة', 'closes_at' => now()->addDays(30)->toDateString()]);

        $rows = collect($this->getJson(self::PUBLIC)->json('data'))->keyBy('title');

        $this->assertSame('closing_soon', $rows['قريبة']['status']);
        $this->assertSame('يغلق قريبًا', $rows['قريبة']['status_label']);
        $this->assertSame('open', $rows['بعيدة']['status']);
        $this->assertSame(3, $rows['قريبة']['days_left']);
    }

    public function test_search_filters_sorting_and_pagination(): void
    {
        $finance = Department::create(['name' => 'Finance', 'name_ar' => 'المالية', 'slug' => 'finance', 'status' => 'active', 'is_active' => true]);
        $a = $this->opportunity(['title' => 'ألف مصمم', 'closes_at' => now()->addDays(20)->toDateString()]);
        $b = $this->opportunity(['title' => 'باء محاسب', 'department_id' => $finance->id, 'location_mode' => 'onsite', 'closes_at' => now()->addDays(5)->toDateString()]);
        $c = $this->opportunity(['title' => 'جيم كاتب', 'summary' => 'كتابة محتوى تسويقي للمنصة والحملات الإعلامية.']);

        $this->assertSame([$b->id], $this->ids($this->getJson(self::PUBLIC.'?department_id='.$finance->id)));
        $this->assertSame([$b->id], $this->ids($this->getJson(self::PUBLIC.'?location_mode=onsite')));
        $this->assertSame([$a->id], $this->ids($this->getJson(self::PUBLIC.'?q='.urlencode('مصمم'))));
        $this->assertSame([$c->id], $this->ids($this->getJson(self::PUBLIC.'?q='.urlencode('تسويقي'))));
        $this->assertSame([$b->id, $a->id, $c->id], $this->ids($this->getJson(self::PUBLIC.'?sort=deadline')), 'earliest deadline first, no deadline last');
        $this->assertSame([$a->id, $b->id, $c->id], $this->ids($this->getJson(self::PUBLIC.'?sort=title')));
        $this->assertCount(2, $this->getJson(self::PUBLIC.'?per_page=2')->json('data'));
        $this->assertSame(2, $this->getJson(self::PUBLIC.'?per_page=2')->json('meta.last_page'));
        $this->assertCount(1, $this->getJson(self::PUBLIC.'?per_page=2&page=2')->json('data'));
    }

    public function test_filter_options_only_list_departments_that_have_open_opportunities(): void
    {
        $empty = Department::create(['name' => 'Empty', 'name_ar' => 'فارغة', 'slug' => 'empty', 'status' => 'active', 'is_active' => true]);
        $this->opportunity();
        $this->opportunity(['department_id' => $empty->id, 'status' => 'draft']);

        $filters = $this->getJson(self::PUBLIC)->json('filters');

        $this->assertSame([['id' => $this->marketing->id, 'name' => 'التسويق والإعلام', 'count' => 1]], $filters['departments']);
        $this->assertSame(['remote', 'onsite', 'hybrid'], array_column($filters['location_modes'], 'value'));
    }

    public function test_bad_query_parameters_are_rejected(): void
    {
        $this->getJson(self::PUBLIC.'?sort=random')->assertStatus(422);
        $this->getJson(self::PUBLIC.'?location_mode=moon')->assertStatus(422);
        $this->getJson(self::PUBLIC.'?per_page=500')->assertStatus(422);
        $this->getJson(self::PUBLIC.'?department_id=abc')->assertStatus(422);
        $this->getJson(self::PUBLIC.'?q='.str_repeat('x', 200))->assertStatus(422);
    }

    public function test_an_empty_listing_is_an_empty_list_not_an_error(): void
    {
        $this->opportunity(['status' => 'draft']);

        $res = $this->getJson(self::PUBLIC)->assertOk();

        $this->assertSame([], $res->json('data'));
        $this->assertSame(0, $res->json('meta.total'));
    }

    public function test_the_listing_does_not_query_per_row(): void
    {
        foreach (range(1, 12) as $i) {
            $this->opportunity(['title' => "فرصة {$i}"]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson(self::PUBLIC)->assertOk();

        $this->assertLessThanOrEqual(8, count(DB::getQueryLog()), 'one query per row would exceed this');
    }

    // ═══ Phase 4 — details ═══════════════════════════════════════════════════

    public function test_a_published_opportunity_has_a_full_public_details_page(): void
    {
        $o = $this->opportunity(['start_date' => now()->addMonth()->toDateString(), 'closes_at' => now()->addDays(10)->toDateString()]);

        $d = $this->getJson(self::PUBLIC.'/'.$o->id)->assertOk()->json('data');

        $this->assertSame(['تصميم منشورات المنصة'], $d['responsibilities']);
        $this->assertSame(['تصميم جرافيك', 'صناعة المحتوى'], $d['required_skills']);
        $this->assertSame(['مونتاج'], $d['preferred_skills']);
        $this->assertSame('3 أشهر', $d['duration']);
        $this->assertSame(2, $d['volunteers_needed']);
        $this->assertTrue($d['accepting_applications']);
        $this->assertNull($d['closed_reason']);
        $this->assertSame('قدّم عبر المنصة.', $d['application_instructions']);
        $this->assertStringNotContainsString('incoming_request', json_encode($d));
    }

    public function test_closed_and_expired_opportunities_can_be_opened_but_say_applications_are_closed(): void
    {
        $closed = $this->opportunity(['status' => 'closed']);
        $expired = $this->opportunity(['closes_at' => now()->subDays(2)->toDateString()]);

        $c = $this->getJson(self::PUBLIC.'/'.$closed->id)->assertOk()->json('data');
        $e = $this->getJson(self::PUBLIC.'/'.$expired->id)->assertOk()->json('data');

        $this->assertFalse($c['accepting_applications']);
        $this->assertSame('closed', $c['closed_reason']);
        $this->assertFalse($e['accepting_applications']);
        $this->assertSame('deadline_passed', $e['closed_reason']);
        $this->assertSame('closed', $e['status']);
    }

    public function test_drafts_archived_and_unknown_opportunities_are_indistinguishable_404s(): void
    {
        $draft = $this->opportunity(['status' => 'draft']);
        $archived = $this->opportunity(['status' => 'archived']);

        $a = $this->getJson(self::PUBLIC.'/'.$draft->id)->assertNotFound()->json();
        $b = $this->getJson(self::PUBLIC.'/'.$archived->id)->assertNotFound()->json();
        $c = $this->getJson(self::PUBLIC.'/999999')->assertNotFound()->json();

        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
        $this->getJson(self::PUBLIC.'/abc')->assertNotFound();
    }

    // ═══ Phase 4 — publishing workflow ═══════════════════════════════════════

    public function test_an_authorized_manager_publishes_a_draft_and_it_becomes_public(): void
    {
        $o = $this->opportunity(['status' => 'draft']);
        $hr = $this->user('hr_manager');
        $this->getJson(self::PUBLIC.'/'.$o->id)->assertNotFound();

        $res = $this->as($hr)->postJson(self::ADMIN.'/'.$o->id.'/publish')->assertOk()->json('data');

        $this->assertSame('published', $res['status']);
        $this->assertTrue($res['is_published']);
        $this->assertTrue($res['accepts_applications']);
        $row = $o->fresh();
        $this->assertSame('published', $row->status);
        $this->assertNotNull($row->published_at);
        $this->assertSame($hr->id, $row->published_by);
        $this->assertContains($o->id, $this->ids($this->getJson(self::PUBLIC)));
        $this->assertDatabaseHas('audit_logs', ['action' => 'opportunity_published', 'entity_id' => $o->id]);
    }

    public function test_only_users_who_may_manage_can_publish_unpublish_close_create_or_edit(): void
    {
        $o = $this->opportunity(['status' => 'draft']);
        $payload = ['title' => 'عنوان الفرصة', 'summary' => 'وصف مختصر للفرصة التطوعية'];

        $this->postJson(self::ADMIN.'/'.$o->id.'/publish')->assertUnauthorized();
        $leader = $this->user('department_manager');
        $this->marketing->forceFill(['leader_id' => $leader->id])->save();
        foreach (['student', 'instructor', 'volunteer', 'support_agent', 'executive_admin', $leader] as $who) {
            $this->as($who);
            $this->postJson(self::ADMIN.'/'.$o->id.'/publish')->assertForbidden();
            $this->postJson(self::ADMIN.'/'.$o->id.'/unpublish')->assertForbidden();
            $this->postJson(self::ADMIN.'/'.$o->id.'/close')->assertForbidden();
            $this->postJson(self::ADMIN, $payload)->assertForbidden();
            $this->putJson(self::ADMIN.'/'.$o->id, $payload)->assertForbidden();
        }
        $this->assertSame('draft', $o->fresh()->status);
        $this->assertNull($o->fresh()->published_at);
        $this->assertNotContains($o->id, $this->ids($this->getJson(self::PUBLIC)));
    }

    public function test_publishing_validates_the_mandatory_content_and_changes_nothing_when_it_fails(): void
    {
        $o = $this->opportunity(['status' => 'draft', 'summary' => 'قصير', 'responsibilities' => [], 'required_skills' => [], 'location_mode' => null]);

        $res = $this->as('hr_manager')->postJson(self::ADMIN.'/'.$o->id.'/publish')->assertStatus(422);

        foreach (['summary', 'responsibilities', 'required_skills', 'location_mode'] as $field) {
            $this->assertArrayHasKey($field, $res->json('errors'), $field);
        }
        $this->assertSame('أضف مهمة واحدة على الأقل.', $res->json('errors.responsibilities.0'));
        $this->assertSame('draft', $o->fresh()->status);
        $this->assertNotContains($o->id, $this->ids($this->getJson(self::PUBLIC)));
    }

    public function test_a_deadline_in_the_past_blocks_publishing(): void
    {
        $o = $this->opportunity(['status' => 'draft', 'closes_at' => now()->subDay()->toDateString()]);

        $res = $this->as('hr_manager')->postJson(self::ADMIN.'/'.$o->id.'/publish')->assertStatus(422);

        $this->assertSame('آخر موعد للتقديم يجب أن يكون اليوم أو بعده.', $res->json('errors.closes_at.0'));
    }

    public function test_the_payload_can_never_set_the_status_or_publication_fields(): void
    {
        $hr = $this->as('hr_manager');
        $payload = ['title' => 'عنوان الفرصة', 'summary' => 'وصف مختصر للفرصة التطوعية', 'status' => 'published', 'published_at' => now()->toDateTimeString(), 'published_by' => 1, 'incoming_request_id' => $this->incoming()->id];

        $id = $hr->postJson(self::ADMIN, $payload)->assertCreated()->json('data.id');
        $row = VolunteerOpportunity::find($id);
        $this->assertSame('draft', $row->status);
        $this->assertNull($row->published_at);
        $this->assertNull($row->incoming_request_id, 'an independent opportunity cannot be linked to a request through the payload');

        $this->putJson(self::ADMIN.'/'.$id, $payload)->assertOk();
        $this->assertSame('draft', $row->fresh()->status);
        $this->assertNotContains($id, $this->ids($this->getJson(self::PUBLIC)));
    }

    public function test_a_draft_can_be_edited_before_publishing(): void
    {
        $o = $this->opportunity(['status' => 'draft']);

        $this->as('hr_manager')->putJson(self::ADMIN.'/'.$o->id, [
            'title' => 'عنوان معدّل للفرصة', 'summary' => 'وصف جديد مفصل للفرصة التطوعية في الإدارة', 'responsibilities' => ['مهمة جديدة'], 'required_skills' => ['مهارة'],
            'location_mode' => 'hybrid', 'closes_at' => now()->addDays(14)->toDateString(), 'duration' => 'شهران',
        ])->assertOk()->assertJsonPath('data.title', 'عنوان معدّل للفرصة');

        $this->postJson(self::ADMIN.'/'.$o->id.'/publish')->assertOk();
        $d = $this->getJson(self::PUBLIC.'/'.$o->id)->json('data');
        $this->assertSame('عنوان معدّل للفرصة', $d['title']);
        $this->assertSame('hybrid', $d['location_mode']);
        $this->assertSame('شهران', $d['duration']);
    }

    public function test_editing_a_published_opportunity_cannot_strip_its_mandatory_content(): void
    {
        $o = $this->opportunity();

        $this->as('hr_manager')->putJson(self::ADMIN.'/'.$o->id, ['title' => 'عنوان آخر للفرصة', 'summary' => 'وصف مختصر طويل بما يكفي للفرصة', 'required_skills' => [], 'responsibilities' => ['مهمة']])->assertStatus(422);

        $row = $o->fresh();
        $this->assertSame(['تصميم جرافيك', 'صناعة المحتوى'], $row->required_skills, 'the rejected edit was rolled back');
        $this->assertSame('فرصة تطوعية: مصمم محتوى', $row->title);
    }

    public function test_unpublish_returns_it_to_draft_and_removes_it_from_the_public_site(): void
    {
        $o = $this->opportunity();
        $this->assertContains($o->id, $this->ids($this->getJson(self::PUBLIC)));

        $this->as('hr_manager')->postJson(self::ADMIN.'/'.$o->id.'/unpublish')->assertOk()->assertJsonPath('data.status', 'draft');

        $this->assertNull($o->fresh()->published_at);
        $this->getJson(self::PUBLIC.'/'.$o->id)->assertNotFound();
        $this->postJson(self::ADMIN.'/'.$o->id.'/unpublish')->assertStatus(422);
    }

    public function test_closing_stops_new_applications_but_keeps_the_existing_ones_reviewable(): void
    {
        $request = $this->incoming();
        $o = $this->opportunity(['incoming_request_id' => $request->id]);
        $this->apply($o)->assertCreated();

        $this->as('hr_manager')->postJson(self::ADMIN.'/'.$o->id.'/close')->assertOk()->assertJsonPath('data.status', 'closed');

        $this->assertNotContains($o->id, $this->ids($this->getJson(self::PUBLIC)), 'no longer listed');
        $this->assertFalse($this->getJson(self::PUBLIC.'/'.$o->id)->assertOk()->json('data.accepting_applications'));
        $this->apply($o, ['email' => 'another@example.com'])->assertStatus(409)->assertJsonPath('message', 'أُغلق باب التقديم على هذه الفرصة.');
        $this->assertSame(1, VolunteerRequest::count());
        $this->getJson(self::ADMIN.'/'.$o->id.'/applicants')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/admin/hr-requests/'.$request->id.'/applicants')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_a_closed_opportunity_can_be_reopened_while_its_deadline_is_valid(): void
    {
        $o = $this->opportunity(['status' => 'closed']);

        $this->as('hr_manager')->postJson(self::ADMIN.'/'.$o->id.'/publish')->assertOk();

        $this->assertContains($o->id, $this->ids($this->getJson(self::PUBLIC)));
    }

    public function test_publishing_and_closing_send_nothing(): void
    {
        $o = $this->opportunity(['status' => 'draft']);
        $before = [DB::table('notification_logs')->count(), DB::table('platform_notifications')->count(), DB::table('jobs')->count()];

        $this->as('hr_manager')->postJson(self::ADMIN.'/'.$o->id.'/publish')->assertOk();
        $this->postJson(self::ADMIN.'/'.$o->id.'/close')->assertOk();

        $this->assertSame($before, [DB::table('notification_logs')->count(), DB::table('platform_notifications')->count(), DB::table('jobs')->count()]);
    }

    public function test_the_admin_list_shows_every_state_with_counts_and_is_not_public(): void
    {
        $this->opportunity(['status' => 'draft']);
        $o = $this->opportunity();
        $this->opportunity(['status' => 'closed']);
        $this->apply($o)->assertCreated();

        $this->getJson(self::ADMIN)->assertUnauthorized();
        $this->as('student')->getJson(self::ADMIN)->assertForbidden();

        $res = $this->as('hr_manager')->getJson(self::ADMIN)->assertOk()->json();
        $this->assertSame(['total' => 3, 'draft' => 1, 'published' => 1, 'closed' => 1], $res['stats']);
        $this->assertSame(1, collect($res['data'])->firstWhere('id', $o->id)['applicants_count']);
        $this->assertSame(1, $this->as('hr_manager')->getJson(self::ADMIN.'?status=draft')->json('meta.total'));

        $exec = $this->as('executive_admin')->getJson(self::ADMIN)->assertOk()->json();
        $this->assertFalse($exec['capabilities']['can_manage'], 'executives may look, not change');
    }

    // ═══ Phase 4/5 — independent opportunities ═══════════════════════════════

    public function test_an_independent_opportunity_works_end_to_end_without_an_incoming_request(): void
    {
        $hr = $this->as('hr_manager');
        $id = $hr->postJson(self::ADMIN, [
            'title' => 'فرصة مستقلة للتطوع', 'summary' => 'وصف مفصل لفرصة تطوعية أُنشئت دون طلب احتياج وارد', 'responsibilities' => ['مهمة'], 'required_skills' => ['مهارة'],
            'location_mode' => 'remote', 'department_id' => $this->marketing->id,
        ])->assertCreated()->json('data.id');
        $hr->postJson(self::ADMIN.'/'.$id.'/publish')->assertOk();

        $this->assertNull(VolunteerOpportunity::find($id)->incoming_request_id);
        $this->postJson(self::PUBLIC.'/'.$id.'/applications', $this->applyPayload())->assertCreated();

        $app = VolunteerRequest::first();
        $this->assertSame($id, $app->opportunity_id);
        $this->assertNull($app->opportunity->incomingRequest, 'there is simply no originating request');
        $this->assertSame(1, $this->getJson(self::ADMIN.'/'.$id.'/applicants')->json('data.total'));
    }

    // ═══ Phase 5 — applying ══════════════════════════════════════════════════

    public function test_a_valid_application_is_stored_pending_and_linked_to_the_opportunity(): void
    {
        $o = $this->opportunity();

        $res = $this->apply($o)->assertCreated();

        $this->assertTrue($res->json('success'));
        $this->assertSame(['success', 'message'], array_keys($res->json()), 'nothing about the stored record is echoed back');
        $app = VolunteerRequest::sole();
        $this->assertSame($o->id, $app->opportunity_id);
        $this->assertSame('pending', $app->status, 'enters the existing review queue; nothing is accepted or assigned');
        $this->assertSame('sara.ahmad@example.com', $app->email, 'normalized');
        $this->assertSame('التسويق والإعلام', $app->desired_department);
        $this->assertNull($app->reviewed_by);
        $this->assertNull($app->accepted_at);
        $this->assertDatabaseHas('notification_logs', ['type' => 'volunteer_confirmation', 'recipient' => 'sara.ahmad@example.com']);
    }

    public function test_a_failure_after_the_application_is_stored_does_not_become_an_error(): void
    {
        $o = $this->opportunity();
        $this->user('hr_manager'); // a recipient, so the HR notification job really is dispatched
        // Every queued job (the confirmation email, the HR notification) fails as soon as it runs.
        \Illuminate\Support\Facades\Queue::before(function () {
            throw new \RuntimeException('queue unavailable');
        });

        $this->apply($o)->assertCreated();

        $this->assertSame(1, VolunteerRequest::where('opportunity_id', $o->id)->count());
        // ...so a retry is not told they "already applied" because of an error they never saw.
        $this->apply($o)->assertStatus(409);
    }

    public function test_a_signed_in_visitor_and_an_anonymous_one_apply_the_same_way(): void
    {
        $o = $this->opportunity();

        // A guest, a guest carrying a stale/invalid token, and a signed-in student: none is rejected or treated differently.
        $this->apply($o, ['email' => 'guest@example.com'])->assertCreated();
        $this->withHeader('Authorization', 'Bearer 999|not-a-real-token')->apply($o, ['email' => 'stale@example.com'])->assertCreated();
        $this->as($this->user('student'))->apply($o, ['email' => 'student@example.com'])->assertCreated();

        $this->assertSame(3, VolunteerRequest::where('opportunity_id', $o->id)->count());
        $this->assertSame(['pending'], VolunteerRequest::pluck('status')->unique()->values()->all());
    }

    public function test_a_second_application_to_the_same_opportunity_is_refused_whatever_the_email_case(): void
    {
        $o = $this->opportunity();
        $this->apply($o)->assertCreated();

        $this->apply($o, ['email' => 'SARA.AHMAD@example.com', 'full_name' => 'اسم آخر'])->assertStatus(409)->assertJsonPath('message', 'لقد قدّمت على هذه الفرصة مسبقًا. سيتواصل معك فريق الموارد البشرية.');

        $this->assertSame(1, VolunteerRequest::count());
    }

    public function test_the_database_itself_refuses_a_duplicate_application(): void
    {
        $o = $this->opportunity();
        $this->apply($o)->assertCreated();
        $row = VolunteerRequest::sole();

        $this->expectException(QueryException::class);
        VolunteerRequest::forceCreate($row->only(['full_name', 'email', 'phone', 'country', 'city', 'gender', 'desired_department', 'experience_level', 'availability', 'motivation']) + ['opportunity_id' => $o->id, 'status' => 'pending']);
    }

    public function test_the_same_person_may_apply_to_different_opportunities_and_still_use_the_general_form(): void
    {
        $a = $this->opportunity();
        $b = $this->opportunity(['title' => 'فرصة أخرى']);

        $this->apply($a)->assertCreated();
        $this->apply($b)->assertCreated();
        // The general (non-opportunity) form is untouched by the unique pair: NULLs never collide.
        VolunteerRequest::forceCreate(['full_name' => 'سارة', 'email' => 'sara.ahmad@example.com', 'phone' => '1', 'country' => 'x', 'city' => 'y', 'gender' => 'female', 'desired_department' => 'ز', 'experience_level' => 'متوسط', 'availability' => '3', 'motivation' => 'نص كافٍ للتطوع والمساهمة', 'status' => 'pending']);
        VolunteerRequest::forceCreate(['full_name' => 'سارة', 'email' => 'sara.ahmad@example.com', 'phone' => '1', 'country' => 'x', 'city' => 'y', 'gender' => 'female', 'desired_department' => 'ز', 'experience_level' => 'متوسط', 'availability' => '3', 'motivation' => 'نص كافٍ للتطوع والمساهمة', 'status' => 'pending']);

        $this->assertSame(4, VolunteerRequest::count());
    }

    public function test_application_validation_errors_are_clear_and_store_nothing(): void
    {
        $o = $this->opportunity();

        $res = $this->postJson(self::PUBLIC.'/'.$o->id.'/applications', [])->assertStatus(422);
        foreach (['full_name', 'email', 'phone', 'country', 'city', 'gender', 'experience_level', 'availability', 'motivation', 'agree_terms'] as $f) {
            $this->assertArrayHasKey($f, $res->json('errors'), $f);
        }
        $this->assertSame('اكتب لماذا ترغب في التطوع.', $res->json('errors.motivation.0'));

        $this->apply($o, ['email' => 'not-an-email', 'motivation' => 'قصير', 'gender' => 'x', 'agree_terms' => false])->assertStatus(422);
        $this->assertSame(0, VolunteerRequest::count());
    }

    public function test_applications_are_refused_for_drafts_unknown_and_expired_opportunities(): void
    {
        $draft = $this->opportunity(['status' => 'draft']);
        $expired = $this->opportunity(['closes_at' => now()->subDay()->toDateString()]);

        $this->apply($draft)->assertNotFound();
        $this->postJson(self::PUBLIC.'/999999/applications', $this->applyPayload())->assertNotFound();
        $this->apply($expired)->assertStatus(409);
        $this->assertSame(0, VolunteerRequest::count());
    }

    public function test_the_public_application_endpoint_is_rate_limited(): void
    {
        $o = $this->opportunity();

        foreach (range(1, 5) as $i) {
            $this->postJson(self::PUBLIC.'/'.$o->id.'/applications', [])->assertStatus(422);
        }

        $this->postJson(self::PUBLIC.'/'.$o->id.'/applications', [])->assertStatus(429);
    }

    // ═══ Phase 5 — linking to the incoming request ═══════════════════════════

    public function test_the_application_links_to_the_originating_request_through_the_stored_keys(): void
    {
        $request = $this->incoming();
        $o = $this->opportunity(['incoming_request_id' => $request->id]);
        // A different request with an identical title and department must not be touched.
        $other = $this->incoming(['request_type' => 'مصمم محتوى']);
        $this->opportunity(['incoming_request_id' => $other->id]);

        $this->apply($o)->assertCreated();

        $app = VolunteerRequest::sole();
        $this->assertSame($request->id, $app->opportunity->incomingRequest->id);
        $this->assertSame($app->id, $request->opportunityDraft->applications->first()->id);
        $this->assertCount(0, $other->opportunityDraft->applications);
    }

    public function test_an_application_changes_neither_the_request_nor_any_status_by_itself(): void
    {
        $request = $this->incoming(['status' => 'in_progress']);
        $o = $this->opportunity(['incoming_request_id' => $request->id]);

        $this->apply($o)->assertCreated();
        $this->as('hr_manager')->postJson(self::ADMIN.'/'.$o->id.'/close')->assertOk();
        $this->as('hr_manager')->patchJson('/api/admin/volunteer-requests/'.VolunteerRequest::sole()->id.'/status', ['status' => 'accepted'])->assertOk();

        $this->assertSame('in_progress', $request->fresh()->status, 'applying, closing and accepting never complete or change the request');
        $this->assertSame('accepted', VolunteerRequest::sole()->status);
        $this->assertSame($o->id, VolunteerRequest::sole()->opportunity_id, 'history is kept after review');
    }

    // ═══ Phase 5 — the incoming request drawer ═══════════════════════════════

    public function test_hr_sees_the_requests_applicants_with_their_opportunity_and_no_contact_details(): void
    {
        $request = $this->incoming();
        $o = $this->opportunity(['incoming_request_id' => $request->id]);
        $this->apply($o, ['email' => 'one@example.com', 'full_name' => 'متقدمة أولى'])->assertCreated();
        $this->apply($o, ['email' => 'two@example.com', 'full_name' => 'متقدم ثان'])->assertCreated();
        VolunteerRequest::where('email', 'two@example.com')->update(['status' => 'reviewing', 'admin_notes' => 'SECRET-NOTE', 'cv_file_path' => 'secret/cv.pdf']);

        $res = $this->as('hr_manager')->getJson('/api/admin/hr-requests/'.$request->id.'/applicants')->assertOk();
        $data = $res->json('data');

        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['counts']['pending']);
        $this->assertSame(1, $data['counts']['reviewing']);
        $this->assertSame($o->id, $data['opportunity']['id']);
        $this->assertSame('منشورة', $data['opportunity']['status_label']);
        $this->assertTrue($data['opportunity']['accepts_applications']);
        $first = $data['items'][0];
        $this->assertSame('direct_application', $first['source']);
        $this->assertSame(['id' => $o->id, 'title' => $o->title], $first['opportunity']);
        $this->assertStringStartsWith('/dashboard/hr/volunteer-requests/', $first['url']);
        $text = json_encode($res->json(), JSON_UNESCAPED_UNICODE);
        foreach (['@example.com', '+31600000000', 'SECRET', 'secret/cv.pdf', 'motivation', 'admin_notes', 'phone', '"email"'] as $needle) {
            $this->assertStringNotContainsString($needle, $text, $needle);
        }
    }

    public function test_a_request_with_no_opportunity_has_an_empty_applicant_list(): void
    {
        $request = $this->incoming();
        $this->apply($this->opportunity())->assertCreated(); // an unrelated opportunity's applicant

        $data = $this->as('hr_manager')->getJson('/api/admin/hr-requests/'.$request->id.'/applicants')->assertOk()->json('data');

        $this->assertSame(0, $data['total']);
        $this->assertSame([], $data['items']);
        $this->assertNull($data['opportunity']);
    }

    public function test_applicant_data_is_protected_by_authorization(): void
    {
        $request = $this->incoming();
        $o = $this->opportunity(['incoming_request_id' => $request->id]);
        $this->apply($o)->assertCreated();

        $this->getJson('/api/admin/hr-requests/'.$request->id.'/applicants')->assertUnauthorized();
        $leader = $this->user('department_manager');
        $this->marketing->forceFill(['leader_id' => $leader->id])->save();
        foreach (['student', 'instructor', 'volunteer', 'support_agent', 'executive_admin', $leader] as $who) {
            $res = $this->as($who)->getJson('/api/admin/hr-requests/'.$request->id.'/applicants')->assertForbidden();
            $this->assertStringNotContainsString('سارة', $res->getContent());
            $this->getJson(self::ADMIN.'/'.$o->id.'/applicants')->assertForbidden();
        }

        // manage_team without view_volunteers manages opportunities but cannot read applicants.
        $role = Role::create(['name' => 'staffing_clerk', 'display_name' => 'staffing_clerk', 'is_system' => false]);
        $role->grantPermission('manage_team');
        $clerk = User::factory()->create(['role' => 'staffing_clerk', 'is_active' => true]);
        $this->as($clerk)->getJson(self::ADMIN)->assertOk();
        $this->getJson('/api/admin/hr-requests/'.$request->id.'/applicants')->assertForbidden();
        $role->grantPermission('view_volunteers');
        $this->as($clerk->fresh())->getJson('/api/admin/hr-requests/'.$request->id.'/applicants')->assertOk();
    }

    public function test_the_request_list_reports_the_opportunity_state_and_applicant_count(): void
    {
        $request = $this->incoming();
        $o = $this->opportunity(['incoming_request_id' => $request->id]);
        $this->apply($o)->assertCreated();

        $draft = $this->as('hr_manager')->getJson('/api/admin/hr-requests/incoming')->assertOk()->json('data.0.draft');

        $this->assertSame(['id' => $o->id, 'title' => $o->title, 'status' => 'published', 'status_label' => 'منشورة', 'applicants_count' => 1], $draft);
    }

    // ═══ Phase 5 — integration with Smart Matching ═══════════════════════════

    public function test_matching_distinguishes_direct_applicants_from_suggestions_without_duplicates(): void
    {
        $request = $this->incoming();
        $o = $this->opportunity(['incoming_request_id' => $request->id]);
        $this->apply($o)->assertCreated();
        $direct = VolunteerRequest::sole();

        // A general application and an application to some OTHER opportunity: both are suggestions.
        $general = VolunteerRequest::forceCreate(['full_name' => 'خالد', 'email' => 'g@example.com', 'phone' => '1', 'country' => 'x', 'city' => 'y', 'gender' => 'male', 'desired_department' => 'التسويق', 'experience_level' => 'متوسط',
            'skills' => 'تصميم جرافيك، صناعة المحتوى', 'availability' => '3', 'motivation' => 'نص كافٍ للتطوع والمساهمة', 'status' => 'pending']);
        $elsewhere = $this->opportunity(['title' => 'فرصة غير مرتبطة']);
        $this->apply($elsewhere, ['email' => 'else@example.com', 'full_name' => 'لينا'])->assertCreated();

        $res = $this->as('hr_manager')->getJson('/api/admin/hr-requests/'.$request->id.'/volunteer-matches?min_score=0')->assertOk();
        $rows = collect($res->json('data'));

        $this->assertSame($rows->pluck('id')->unique()->count(), $rows->count(), 'nobody listed twice');
        $this->assertSame('direct_application', $rows->firstWhere('id', $direct->id)['source']);
        $this->assertSame('تقدّم مباشرة على الفرصة', $rows->firstWhere('id', $direct->id)['source_label']);
        $this->assertSame('smart_match', $rows->firstWhere('id', $general->id)['source']);
        $this->assertSame('smart_match', $rows->firstWhere('id', VolunteerRequest::where('email', 'else@example.com')->value('id'))['source']);
        $this->assertSame(1, $res->json('meta.direct_applicants_in_results'));
        // Matching still never assigns anyone.
        $this->assertSame('pending', $direct->fresh()->status);
    }

    // ═══ Regression of phases 1–3 and access ═════════════════════════════════

    public function test_the_phase_three_draft_workflow_still_works_and_feeds_the_publish_workflow(): void
    {
        $request = $this->incoming();
        $hr = $this->as('hr_manager');
        $payload = $hr->getJson('/api/admin/hr-requests/'.$request->id.'/opportunity-draft/suggestion')->assertOk()->json('data');
        $payload['location_mode'] = 'remote';

        $created = $hr->postJson('/api/admin/hr-requests/'.$request->id.'/opportunity-draft', $payload)->assertCreated()->json('data');
        $this->assertSame('draft', $created['status']);
        $hr->postJson('/api/admin/hr-requests/'.$request->id.'/opportunity-draft', $payload)->assertStatus(409);

        $this->assertNotContains($created['id'], $this->ids($this->getJson(self::PUBLIC)), 'a draft is never public');
        $hr->postJson(self::ADMIN.'/'.$created['id'].'/publish')->assertOk();
        $this->assertContains($created['id'], $this->ids($this->getJson(self::PUBLIC)));
        $this->assertSame($request->id, VolunteerOpportunity::find($created['id'])->incoming_request_id, 'the link to the request survives publishing');
        $hr->putJson('/api/admin/hr-requests/'.$request->id.'/opportunity-draft', $payload)->assertStatus(404);
    }

    public function test_the_deadline_and_duration_are_saved_through_the_incoming_request_draft_endpoint(): void
    {
        $request = $this->incoming();
        $hr = $this->as('hr_manager');
        $payload = $hr->getJson('/api/admin/hr-requests/'.$request->id.'/opportunity-draft/suggestion')->assertOk()->json('data');
        $closes = now()->addDays(30)->toDateString();

        $created = $hr->postJson('/api/admin/hr-requests/'.$request->id.'/opportunity-draft', array_merge($payload, ['duration' => '3 أشهر', 'closes_at' => $closes, 'location_mode' => 'remote']))
            ->assertCreated()->json('data');

        $this->assertSame('3 أشهر', $created['duration']);
        $this->assertSame($closes, substr((string) $created['closes_at'], 0, 10));
        $row = VolunteerOpportunity::find($created['id']);
        $this->assertSame('3 أشهر', $row->duration);
        $this->assertSame($closes, $row->closes_at->toDateString());

        $later = now()->addDays(60)->toDateString();
        $hr->putJson('/api/admin/hr-requests/'.$request->id.'/opportunity-draft', array_merge($payload, ['duration' => '6 أشهر', 'closes_at' => $later, 'location_mode' => 'remote']))->assertOk();
        $this->assertSame($later, $row->fresh()->closes_at->toDateString());

        $hr->putJson('/api/admin/hr-requests/'.$request->id.'/opportunity-draft', array_merge($payload, ['closes_at' => 'not-a-date']))
            ->assertStatus(422)->assertJsonValidationErrors(['closes_at']);
    }

    public function test_the_page_is_an_admin_only_catalog_entry_given_to_hr_managers_by_default(): void
    {
        $e = PageAccessCatalog::find('hr.volunteer_opportunities');

        $this->assertNotNull($e);
        $this->assertSame('/dashboard/hr/opportunities', $e->primaryRoute);
        $this->assertFalse($e->delegatable);
        $holders = array_keys(array_filter(RoleDefaultMap::all(), fn ($keys) => in_array('hr.volunteer_opportunities', $keys, true)));
        $this->assertSame(['hr_manager'], $holders);
    }
}
