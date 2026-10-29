<?php

namespace Tests\Feature\Forms;

use App\Models\DynamicForm;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesFormResponses;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Smart Forms Phase 7: analytics computed from stored responses only, over the same rows the response table shows, under the
 * same permission.
 */
class FormAnalyticsTest extends TestCase
{
    use MakesFormResponses;
    use MakesForms;
    use RefreshDatabase;

    private $dept;

    private $owner;

    private DynamicForm $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = $this->makeDepartment('قسم أ');
        $this->owner = $this->makeUser('operations_manager', $this->dept);
        $this->form = $this->createPublishedForm($this->owner, [
            ['label' => 'التقييم', 'type' => 'star_rating', 'required' => true, 'config' => ['scale' => ['max' => 5]]],
            ['label' => 'المدينة', 'type' => 'radio', 'options' => ['أمستردام', 'روتردام', 'لاهاي']],
            ['label' => 'الاهتمامات', 'type' => 'checkbox', 'options' => ['برمجة', 'تصميم', 'لغات']],
            ['label' => 'هل ستعود؟', 'type' => 'yes_no'],
            ['label' => 'ملاحظات سرية', 'type' => 'textarea'],
        ], ['department_id' => $this->dept->id]);
    }

    private function at(string $date): array
    {
        return ['submitted_at' => CarbonImmutable::parse($date.' 12:00', 'Europe/Amsterdam')->utc()];
    }

    private function seedResponses(): void
    {
        $this->respond($this->form, ['التقييم' => 5, 'المدينة' => 'أمستردام', 'الاهتمامات' => ['برمجة', 'لغات'], 'هل ستعود؟' => 'yes', 'ملاحظات سرية' => 'نص خاص جداً'], $this->at('2026-03-02'));
        $this->respond($this->form, ['التقييم' => 4, 'المدينة' => 'أمستردام', 'الاهتمامات' => ['برمجة'], 'هل ستعود؟' => 'yes'], $this->at('2026-03-02') + ['user_id' => $this->owner->id]);
        $this->respond($this->form, ['التقييم' => 2, 'المدينة' => 'روتردام', 'هل ستعود؟' => 'no'], $this->at('2026-03-05') + ['status' => 'completed']);
        // the previous period, for the comparison
        $this->respond($this->form, ['التقييم' => 3], $this->at('2026-02-25'));
    }

    private function analytics(array $query = [])
    {
        return $this->actingAs($this->owner, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/analytics?".http_build_query($query))->assertOk()->json('data');
    }

    private function question(array $data, string $label): array
    {
        return collect($data['questions'])->firstWhere('label', $label);
    }

    public function test_totals_and_status_counts_match_the_stored_responses(): void
    {
        $this->seedResponses();
        $data = $this->analytics();

        $this->assertSame(4, $data['totals']['responses']);
        $this->assertSame(1, $data['totals']['signed_in']);
        $this->assertSame(3, $data['totals']['anonymous']);
        $this->assertSame(1, $data['totals']['by_status']['completed']);
        $this->assertSame(3, $data['totals']['by_status']['new']);
        $this->assertNull($data['completion']['rate'], 'abandoned attempts are not stored, so no completion rate is invented');
        $this->assertSame('not_tracked', $data['completion']['reason']);
    }

    public function test_question_statistics(): void
    {
        $this->seedResponses();
        $data = $this->analytics();

        $rating = $this->question($data, 'التقييم');
        $this->assertSame(4, $rating['answered']);
        $this->assertSame(100.0, (float) $rating['answer_rate']);
        $this->assertSame('numeric', $rating['stats']['kind']);
        $this->assertEquals(3.5, $rating['stats']['average']);
        $this->assertEquals([2, 5], [$rating['stats']['min'], $rating['stats']['max']]);
        $this->assertSame(['1', '2', '3', '4', '5'], array_column($rating['stats']['distribution'], 'value'), 'every step of the scale, zero included');
        $this->assertSame([0, 1, 1, 1, 1], array_column($rating['stats']['distribution'], 'count'));

        $city = $this->question($data, 'المدينة');
        $this->assertSame(3, $city['answered']);
        $this->assertSame(['أمستردام' => 2, 'روتردام' => 1, 'لاهاي' => 0], array_column($city['stats']['options'], 'count', 'value'));
        $this->assertEquals(66.7, $city['stats']['options'][0]['percent']);

        $interests = $this->question($data, 'الاهتمامات');
        $this->assertTrue($interests['stats']['multiple']);
        $this->assertSame(['برمجة' => 2, 'تصميم' => 0, 'لغات' => 1], array_column($interests['stats']['options'], 'count', 'value'));

        $back = $this->question($data, 'هل ستعود؟');
        $this->assertSame(['yes' => 2, 'no' => 1], array_column($back['stats']['options'], 'count', 'value'));
    }

    public function test_free_text_is_counted_never_quoted(): void
    {
        $this->seedResponses();
        $res = $this->actingAs($this->owner, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/analytics")->assertOk();

        $notes = $this->question($res->json('data'), 'ملاحظات سرية');
        $this->assertSame(1, $notes['answered']);
        $this->assertSame(['kind' => 'count'], $notes['stats']);
        $this->assertStringNotContainsString('نص خاص جداً', $res->getContent());
    }

    public function test_filters_and_the_comparison_with_the_previous_period(): void
    {
        $this->seedResponses();
        $data = $this->analytics(['from' => '2026-03-01', 'to' => '2026-03-07']);

        $this->assertSame(3, $data['totals']['responses']);
        $this->assertSame(['current' => 3, 'previous' => 1, 'previous_from' => '2026-02-22', 'previous_to' => '2026-02-28', 'change_percent' => 200], array_map(fn ($v) => is_float($v) ? (int) $v : $v, $data['comparison']));
        $this->assertSame('day', $data['series']['granularity']);
        $this->assertCount(7, $data['series']['points']);
        $this->assertSame(['period' => '2026-03-02', 'count' => 2], $data['series']['points'][1]);

        $completed = $this->analytics(['status' => 'completed']);
        $this->assertSame(1, $completed['totals']['responses']);
        $this->assertSame(1, array_sum($completed['totals']['by_status']), 'the breakdown adds up to the filtered total');
        $this->assertSame(0, $completed['totals']['by_status']['new']);
        $this->assertNull($completed['comparison']);
    }

    public function test_the_series_uses_the_display_timezone(): void
    {
        // 23:30 in Amsterdam on 1 March is already 2 March in neither UTC nor Amsterdam terms only if bucketed in UTC.
        $this->respond($this->form, ['التقييم' => 4], ['submitted_at' => CarbonImmutable::parse('2026-03-01 23:30', 'Europe/Amsterdam')->utc()]);
        $data = $this->analytics(['from' => '2026-03-01', 'to' => '2026-03-02']);
        $this->assertSame([['period' => '2026-03-01', 'count' => 1], ['period' => '2026-03-02', 'count' => 0]], $data['series']['points']);
    }

    public function test_answer_rate_counts_only_the_versions_that_asked_the_question(): void
    {
        $this->respond($this->form, ['التقييم' => 5]);
        $this->republish($this->owner, $this->form, fn ($qs) => [...$qs, ['label' => 'سؤال جديد', 'type' => 'text']]);
        $this->respond($this->form, ['التقييم' => 4, 'سؤال جديد' => 'نعم']);

        $new = $this->question($this->analytics(), 'سؤال جديد');
        $this->assertSame(1, $new['eligible'], 'the response to version 1 never saw this question');
        $this->assertEquals(100.0, $new['answer_rate']);
    }

    public function test_an_empty_form_has_no_invented_numbers(): void
    {
        $data = $this->analytics();
        $this->assertSame(0, $data['totals']['responses']);
        $this->assertSame([], $data['series']['points']);
        $rating = $this->question($data, 'التقييم');
        $this->assertNull($rating['answer_rate']);
        $this->assertNull($rating['stats']['average']);
        $this->assertNull($this->question($data, 'المدينة')['stats']['options'][0]['percent']);
    }

    public function test_analytics_need_the_right_to_read_the_responses(): void
    {
        $outsider = $this->makeUser('operations_manager', $this->makeDepartment('قسم ب'));
        $this->actingAs($outsider, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/analytics")->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/analytics/export")->assertForbidden();

        // a collaborator who may view the form but not its responses
        $viewer = $this->makeUser('community_manager', $this->makeDepartment('قسم ج'));
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $viewer->id, 'access_level' => 'viewer', 'can_view_responses' => false])->assertCreated();
        $this->actingAs($viewer, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}")->assertOk();
        $this->actingAs($viewer, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/analytics")->assertForbidden();

        $this->actingAs($this->makeUser('student'), 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/analytics")->assertForbidden();
    }

    public function test_the_csv_export_is_safe_and_recorded(): void
    {
        $this->seedResponses();
        $this->respond($this->form, ['التقييم' => 1, 'المدينة' => 'لاهاي']);
        $res = $this->actingAs($this->owner, 'sanctum')->get("/api/operations/forms/{$this->form->id}/analytics/export")->assertOk();

        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('"عدد الردود",5', $csv);
        $this->assertStringContainsString('أمستردام,2,50', $csv);
        $this->assertStringNotContainsString('نص خاص جداً', $csv);
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $this->assertDatabaseHas('activity_logs', ['entity_id' => $this->form->id, 'action' => 'form.analytics_exported']);
    }

    public function test_the_overview_lists_only_forms_whose_responses_the_user_may_read(): void
    {
        $this->seedResponses();
        $otherDept = $this->makeDepartment('قسم ب');
        $other = $this->makeUser('operations_manager', $otherDept);
        $hidden = $this->createPublishedForm($other, [['label' => 'س', 'type' => 'text']], ['department_id' => $otherDept->id]);
        $this->respond($hidden, ['س' => 'x']);

        $data = $this->actingAs($this->owner, 'sanctum')->getJson('/api/operations/form-analytics/overview?from=2026-03-01&to=2026-03-31')->assertOk()->json('data');
        $this->assertSame([$this->form->id], array_column($data['forms'], 'id'));
        $this->assertSame(3, $data['totals']['responses']);
        $this->assertSame(2, $data['forms'][0]['open']);

        $admin = $this->makeUser('admin');
        $all = $this->actingAs($admin, 'sanctum')->getJson('/api/operations/form-analytics/overview')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$this->form->id, $hidden->id], array_column($all['forms'], 'id'));
        $byDept = $this->actingAs($admin, 'sanctum')->getJson("/api/operations/form-analytics/overview?department_id={$otherDept->id}")->assertOk()->json('data');
        $this->assertSame([$hidden->id], array_column($byDept['forms'], 'id'));
    }
}
