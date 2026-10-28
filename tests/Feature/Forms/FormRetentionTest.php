<?php

namespace Tests\Feature\Forms;

use App\Models\DynamicForm;
use App\Models\FormAnswer;
use App\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesFormResponses;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Smart Forms data retention: nothing is deleted unless the form's owner chose a retention period; then only older responses go,
 * with their files and history, and the purge is recorded.
 */
class FormRetentionTest extends TestCase
{
    use MakesFormResponses;
    use MakesForms;
    use RefreshDatabase;

    private $owner;

    private DynamicForm $form;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Storage::fake('public');
        $this->owner = $this->makeUser('operations_manager', $this->makeDepartment('قسم أ'));
        $this->form = $this->createPublishedForm($this->owner, [
            ['label' => 'الاسم', 'type' => 'text'],
            ['label' => 'المرفق', 'type' => 'file'],
        ]);
    }

    private function oldResponse(int $daysAgo, bool $withFile = false): FormSubmission
    {
        $answers = ['الاسم' => 'x'];
        if ($withFile) {
            $answers['المرفق'] = $this->pdfUpload();
        }

        return $this->respond($this->form, $answers, ['submitted_at' => now()->subDays($daysAgo)]);
    }

    public function test_nothing_is_deleted_without_a_retention_period(): void
    {
        $this->oldResponse(4000);
        $this->artisan('forms:purge-responses')->assertSuccessful();
        $this->assertSame(1, FormSubmission::count());
    }

    public function test_only_the_owner_or_a_platform_manager_sets_the_period_within_bounds(): void
    {
        $collaborator = $this->makeUser('community_manager', $this->makeDepartment('قسم ب'));
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $collaborator->id, 'access_level' => 'editor'])->assertCreated();

        $this->actingAs($collaborator, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/retention")->assertOk()->assertJsonPath('data.can_change', false);
        $this->actingAs($collaborator, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/retention", ['retention_days' => 90])->assertForbidden();

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/retention", ['retention_days' => 5])->assertUnprocessable();
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/retention", ['retention_days' => 90])->assertOk()->assertJsonPath('data.retention_days', 90);
        $this->assertDatabaseHas('activity_logs', ['entity_id' => $this->form->id, 'action' => 'form.retention_changed']);
    }

    public function test_the_dry_run_counts_and_the_purge_deletes_older_responses_with_their_files(): void
    {
        $old = $this->oldResponse(100, withFile: true);
        $recent = $this->oldResponse(10);
        $path = FormAnswer::query()->where('submission_id', $old->id)->whereNotNull('file_path')->value('file_path');
        Storage::disk('private')->assertExists($path);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/operations/forms/{$this->form->id}/submissions/{$old->id}/notes", ['body' => 'ملاحظة'])->assertCreated();
        $this->form->forceFill(['retention_days' => 30])->save();

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/retention")->assertJsonPath('data.expired_now', 1);
        $this->artisan('forms:purge-responses --dry-run')->expectsOutputToContain('would be deleted: 1')->assertSuccessful();
        $this->assertSame(2, FormSubmission::count());

        $this->artisan('forms:purge-responses')->expectsOutputToContain('Responses deleted: 1')->assertSuccessful();
        $this->assertSame([$recent->id], FormSubmission::query()->pluck('id')->all());
        $this->assertSame(0, FormAnswer::query()->where('submission_id', $old->id)->count());
        $this->assertDatabaseMissing('form_submission_notes', ['submission_id' => $old->id]);
        Storage::disk('private')->assertMissing($path);
        $this->assertDatabaseHas('activity_logs', ['entity_id' => $this->form->id, 'action' => 'form.responses_purged']);
    }

    public function test_other_forms_are_never_touched(): void
    {
        $other = $this->createPublishedForm($this->owner, [['label' => 'س', 'type' => 'text']]);
        $kept = $this->respond($other, ['س' => 'x'], ['submitted_at' => now()->subDays(400)]);
        $this->oldResponse(400);
        $this->form->forceFill(['retention_days' => 30])->save();

        $this->artisan('forms:purge-responses')->assertSuccessful();
        $this->assertSame([$kept->id], FormSubmission::query()->pluck('id')->all());
    }
}
