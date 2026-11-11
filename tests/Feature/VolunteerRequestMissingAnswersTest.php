<?php

namespace Tests\Feature;

use App\Mail\VolunteerApplicationAdminMail;
use App\Models\Department;
use App\Models\HrRequest;
use App\Models\User;
use App\Models\VolunteerRequest;
use App\Services\VolunteerMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * An unanswered question is stored as "no answer" (NULL), never as an answer the applicant did not give, and
 * nothing that reads an application (API, HR screens, admin email, volunteer matching) breaks on it.
 */
class VolunteerRequestMissingAnswersTest extends TestCase
{
    use RefreshDatabase;

    /** Sentences and labels the application used to invent when a question was left out. */
    private const INVENTED = ['الرغبة في المساهمة في خدمة المجتمع وتطوير المهارات', 'مرن', 'غير محدد'];

    /** What the five-level assessment wizard sends once it stops mapping unrelated answers into legacy fields. */
    private function wizardPayload(array $over = []): array
    {
        return array_merge([
            'full_name' => 'Sara Ahmad', 'email' => 'sara@example.com', 'phone' => '+31600000000', 'country' => 'NL', 'city' => 'Amsterdam',
            'gender' => 'unspecified', 'desired_department' => 'التسويق والإعلام', 'agree_terms' => true,
            'education_level' => 'بكالوريوس', 'volunteer_days_availability' => 'أيام الإجازة فقط',
            'value_add_contributions' => 'تصميم جرافيك وصناعة المحتوى ومونتاج الفيديو',
        ], $over);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true, 'email' => 'admin@example.com']);
    }

    // ── Storage ───────────────────────────────────────────────────────────────────────────────

    public function test_questions_left_out_are_stored_as_null_and_nothing_is_invented(): void
    {
        $this->postJson('/api/volunteer-requests', $this->wizardPayload())->assertCreated();

        $row = VolunteerRequest::firstOrFail();
        $this->assertNull($row->experience_level);
        $this->assertNull($row->availability);
        $this->assertNull($row->motivation);
        $this->assertSame('unspecified', $row->gender, "gender keeps its approved 'unspecified' value");

        // The answers that WERE given stay in the columns they were given for.
        $this->assertSame('بكالوريوس', $row->education_level);
        $this->assertSame('أيام الإجازة فقط', $row->volunteer_days_availability);
        $this->assertSame('تصميم جرافيك وصناعة المحتوى ومونتاج الفيديو', $row->value_add_contributions);

        foreach (['experience_level', 'availability', 'motivation'] as $field) {
            $this->assertNotContains($row->{$field}, self::INVENTED, "$field must not hold an invented answer");
        }
    }

    public function test_answers_that_are_sent_are_stored_verbatim(): void
    {
        $this->postJson('/api/volunteer-requests', $this->wizardPayload([
            'experience_level' => 'متوسط', 'availability' => '5-10 ساعات أسبوعيًا', 'motivation' => 'أريد المساهمة في خدمة المجتمع.',
        ]))->assertCreated();

        $this->assertDatabaseHas('volunteer_requests', [
            'experience_level' => 'متوسط', 'availability' => '5-10 ساعات أسبوعيًا', 'motivation' => 'أريد المساهمة في خدمة المجتمع.',
        ]);
    }

    public function test_the_response_reports_missing_answers_as_null(): void
    {
        $this->postJson('/api/volunteer-requests', $this->wizardPayload())
            ->assertCreated()
            ->assertJsonPath('data.experience_level', null)
            ->assertJsonPath('data.availability', null)
            ->assertJsonPath('data.motivation', null)
            ->assertJsonPath('data.gender', 'unspecified');
    }

    // ── Everything that reads an application tolerates the gaps ─────────────────────────────────

    public function test_submitting_without_an_experience_level_still_reaches_the_admins_by_email(): void
    {
        // The admin notification email is built inside the submit request; a null experience level used to be a TypeError there.
        $this->admin();

        $this->postJson('/api/volunteer-requests', $this->wizardPayload())->assertCreated();

        $this->assertDatabaseCount('volunteer_requests', 1);
    }

    public function test_the_admin_email_says_the_experience_level_was_not_given(): void
    {
        $row = VolunteerRequest::forceCreate([
            'full_name' => 'Sara Ahmad', 'email' => 'sara@example.com', 'phone' => '1', 'country' => 'NL', 'city' => 'A', 'gender' => 'unspecified',
            'desired_department' => 'التسويق', 'experience_level' => null, 'availability' => null, 'motivation' => null, 'status' => 'pending',
        ]);

        $html = VolunteerApplicationAdminMail::fromRequest($row, 'Admin', $row->id)->render();

        $this->assertStringContainsString('لم يُحدَّد', $html);
        foreach (self::INVENTED as $invented) {
            $this->assertStringNotContainsString($invented, $html);
        }
    }

    public function test_the_hr_admin_screens_read_an_application_with_missing_answers(): void
    {
        $admin = $this->admin();
        $this->postJson('/api/volunteer-requests', $this->wizardPayload())->assertCreated();
        $id = VolunteerRequest::firstOrFail()->id;

        $list = $this->actingAs($admin)->getJson('/api/admin/volunteer-requests')->assertOk()->json();
        $row = collect($list['data'] ?? [])->firstWhere('id', $id);
        $this->assertNotNull($row, 'the application is listed');
        $this->assertNull($row['motivation']);
        $this->assertNull($row['availability']);
        $this->assertNull($row['experience_level']);

        $this->actingAs($admin)->getJson("/api/admin/volunteer-requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.motivation', null)
            ->assertJsonPath('data.education_level', 'بكالوريوس')
            ->assertJsonPath('data.value_add_contributions', 'تصميم جرافيك وصناعة المحتوى ومونتاج الفيديو');
    }

    public function test_volunteer_matching_handles_missing_answers_and_still_credits_the_value_add_text(): void
    {
        $department = Department::create(['name' => 'Marketing', 'name_ar' => 'التسويق والإعلام', 'slug' => 'marketing', 'status' => 'active', 'is_active' => true]);
        $request = HrRequest::forceCreate([
            'department_id' => $department->id, 'request_type' => 'مصمم محتوى', 'required_count' => 1,
            'skills_and_specs' => 'تصميم جرافيك، صناعة المحتوى، مونتاج الفيديو', 'status' => 'pending', 'target_audience' => 'hr',
            'created_by' => User::factory()->create()->id,
        ]);
        $base = [
            'phone' => '1', 'country' => 'NL', 'city' => 'A', 'gender' => 'unspecified', 'desired_department' => 'التسويق والإعلام',
            'experience_level' => null, 'availability' => null, 'motivation' => null, 'status' => 'pending',
        ];
        $withText = VolunteerRequest::forceCreate($base + ['full_name' => 'With text', 'email' => 'a@example.com', 'value_add_contributions' => 'خبرة في تصميم جرافيك وصناعة المحتوى ومونتاج الفيديو']);
        $without = VolunteerRequest::forceCreate($base + ['full_name' => 'Without text', 'email' => 'b@example.com']);

        $result = app(VolunteerMatchingService::class)->match($request, 0);

        $scores = collect($result['candidates'])->pluck('score', 'id');
        $this->assertTrue($scores->has($withText->id) && $scores->has($without->id), 'both applicants are scored despite missing answers');
        $this->assertGreaterThan($scores[$without->id], $scores[$withText->id], "the 'what can you add' answer still counts as experience evidence");
    }

    // ── Migration ──────────────────────────────────────────────────────────────────────────────

    public function test_the_three_columns_are_nullable_and_gender_is_not(): void
    {
        $columns = collect(Schema::getColumns('volunteer_requests'))->keyBy('name');

        foreach (['experience_level', 'availability', 'motivation'] as $column) {
            $this->assertTrue($columns[$column]['nullable'], "$column accepts NULL");
        }
        $this->assertFalse($columns['gender']['nullable'], 'gender keeps its approved NOT NULL contract');
    }

    public function test_the_migration_rolls_back_and_forward_without_losing_a_row_or_an_answer(): void
    {
        $answered = VolunteerRequest::forceCreate([
            'full_name' => 'Answered', 'email' => 'a@example.com', 'phone' => '1', 'country' => 'NL', 'city' => 'A', 'gender' => 'male',
            'desired_department' => 'x', 'experience_level' => 'متقدم', 'availability' => '3-5 ساعات', 'motivation' => 'سبب حقيقي للتطوع', 'status' => 'pending',
        ]);
        $unanswered = VolunteerRequest::forceCreate([
            'full_name' => 'Unanswered', 'email' => 'b@example.com', 'phone' => '1', 'country' => 'NL', 'city' => 'A', 'gender' => 'unspecified',
            'desired_department' => 'x', 'experience_level' => null, 'availability' => null, 'motivation' => null, 'status' => 'pending',
        ]);

        $migration = require base_path('database/migrations/2026_11_08_000001_make_volunteer_request_unanswered_fields_nullable.php');

        $migration->down();
        $columns = collect(Schema::getColumns('volunteer_requests'))->keyBy('name');
        $this->assertFalse($columns['motivation']['nullable'], 'rolled back to the old NOT NULL contract');
        $this->assertSame('', $unanswered->fresh()->motivation, 'no answer becomes an empty string, never an invented value');
        $this->assertSame('سبب حقيقي للتطوع', $answered->fresh()->motivation, 'a real answer is untouched');

        $migration->up();
        $columns = collect(Schema::getColumns('volunteer_requests'))->keyBy('name');
        $this->assertTrue($columns['motivation']['nullable']);
        $this->assertSame(2, VolunteerRequest::count(), 'no row was lost');
        $this->assertSame('متقدم', $answered->fresh()->experience_level);
    }
}
