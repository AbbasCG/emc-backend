<?php

namespace Tests\Feature;

use App\Models\AmbassadorApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAmbassadorApplicationExportTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function makeApplication(array $overrides = []): AmbassadorApplication
    {
        return AmbassadorApplication::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'status' => 'new',
            'is_draft' => false,
            'full_name' => 'Applicant Name',
            'email' => 'applicant' . Str::random(6) . '@example.com',
            'mobile_phone' => '+31612345678',
            'nationality' => 'يمني',
            'gender' => 'male',
            'country' => 'هولندا',
            'city' => 'صنعاء',
            'university_name' => 'جامعة صنعاء',
            'major' => 'علوم الحاسوب',
            'motivation_why' => 'أريد المساهمة في التحول الرقمي.',
            'contribution_what' => 'تنظيم فعاليات.',
            'expected_gain' => 'تطوير المهارات.',
        ], $overrides));
    }

    // ── Permissions ──────────────────────────────────────────────────────────

    public function test_super_admin_can_access_export_options(): void
    {
        $user = $this->makeUser('super_admin');
        $this->actingAs($user)->getJson('/api/admin/ambassador-applications/export/options')->assertOk();
    }

    public function test_executive_admin_can_access_export_options(): void
    {
        $user = $this->makeUser('executive_admin');
        $this->actingAs($user)->getJson('/api/admin/ambassador-applications/export/options')->assertOk();
    }

    public function test_hr_manager_can_access_export_options(): void
    {
        $user = $this->makeUser('hr_manager');
        $this->actingAs($user)->getJson('/api/admin/ambassador-applications/export/options')->assertOk();
    }

    public function test_student_cannot_access_export(): void
    {
        $user = $this->makeUser('student');
        $this->actingAs($user)->getJson('/api/admin/ambassador-applications/export/options')->assertStatus(403);
        $this->actingAs($user)->postJson('/api/admin/ambassador-applications/export', [
            'format' => 'csv', 'mode' => 'all',
        ])->assertStatus(403);
    }

    public function test_admin_role_without_the_three_explicit_roles_cannot_export(): void
    {
        // Explicit spec: only super_admin, executive_admin, hr_manager — plain
        // 'admin'/'tech_admin' (which DO have access to the other ambassador
        // routes) must NOT automatically get export access.
        $user = $this->makeUser('admin');
        $this->actingAs($user)->getJson('/api/admin/ambassador-applications/export/options')->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/ambassador-applications/export/options')->assertStatus(401);
    }

    // ── Options endpoint — dynamic, not hardcoded ────────────────────────────

    public function test_options_returns_statuses_dynamically_from_the_model(): void
    {
        $user = $this->makeUser('super_admin');
        $response = $this->actingAs($user)->getJson('/api/admin/ambassador-applications/export/options');

        $response->assertOk();
        $this->assertSame(AmbassadorApplication::allowedStatuses(), $response->json('data.statuses'));
    }

    public function test_options_returns_distinct_countries_actually_present_in_data(): void
    {
        $user = $this->makeUser('super_admin');
        $this->makeApplication(['country' => 'هولندا']);
        $this->makeApplication(['country' => 'اليمن']);
        $this->makeApplication(['country' => 'هولندا']); // duplicate, should collapse

        $response = $this->actingAs($user)->getJson('/api/admin/ambassador-applications/export/options');

        $countries = $response->json('data.countries');
        $this->assertCount(2, $countries);
        $this->assertContains('هولندا', $countries);
        $this->assertContains('اليمن', $countries);
    }

    public function test_options_returns_full_column_registry(): void
    {
        $user = $this->makeUser('super_admin');
        $response = $this->actingAs($user)->getJson('/api/admin/ambassador-applications/export/options');

        $keys = collect($response->json('data.columns'))->pluck('key');
        $this->assertContains('full_name', $keys);
        $this->assertContains('email', $keys);
        $this->assertContains('status', $keys);
    }

    // ── Export is NOT limited to approved applications ───────────────────────

    public function test_export_includes_every_status_not_only_approved(): void
    {
        $user = $this->makeUser('super_admin');
        $this->makeApplication(['status' => 'new', 'full_name' => 'New Applicant']);
        $this->makeApplication(['status' => 'rejected', 'full_name' => 'Rejected Applicant']);
        $this->makeApplication(['status' => 'approved', 'full_name' => 'Approved Applicant']);

        $response = $this->actingAs($user)->postJson('/api/admin/ambassador-applications/export', [
            'format' => 'csv', 'mode' => 'all', 'columns' => ['full_name', 'status'],
        ]);

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('New Applicant', $csv);
        $this->assertStringContainsString('Rejected Applicant', $csv);
        $this->assertStringContainsString('Approved Applicant', $csv);
    }

    // ── CSV format ────────────────────────────────────────────────────────────

    public function test_csv_export_has_utf8_bom_and_selected_columns_only(): void
    {
        $user = $this->makeUser('super_admin');
        $this->makeApplication(['full_name' => 'أحمد محمد', 'email' => 'ahmad@example.com']);

        $response = $this->actingAs($user)->postJson('/api/admin/ambassador-applications/export', [
            'format' => 'csv', 'mode' => 'all', 'columns' => ['full_name', 'email'],
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringStartsWith(chr(0xEF) . chr(0xBB) . chr(0xBF), $csv);
        $this->assertStringContainsString('أحمد محمد', $csv);
        $this->assertStringContainsString('ahmad@example.com', $csv);
        // A column that wasn't selected must not leak into the file.
        $this->assertStringNotContainsString('علوم الحاسوب', $csv);
    }

    public function test_csv_filename_follows_the_specified_format(): void
    {
        $user = $this->makeUser('super_admin');
        $this->makeApplication();

        $response = $this->actingAs($user)->postJson('/api/admin/ambassador-applications/export', [
            'format' => 'csv', 'mode' => 'all', 'columns' => ['full_name'],
        ]);

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertMatchesRegularExpression('/ambassador-applications-\d{4}-\d{2}-\d{2}\.csv/', $disposition);
    }

    public function test_csv_filename_includes_status_suffix_when_filtered_to_one_status(): void
    {
        $user = $this->makeUser('super_admin');
        $this->makeApplication(['status' => 'approved']);

        $response = $this->actingAs($user)->postJson('/api/admin/ambassador-applications/export', [
            'format' => 'csv', 'mode' => 'all', 'statuses' => ['approved'], 'columns' => ['full_name'],
        ]);

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('ambassador-applications-approved-', $disposition);
    }

    // ── XLSX format ───────────────────────────────────────────────────────────

    public function test_xlsx_export_succeeds_and_is_a_valid_zip_based_file(): void
    {
        $user = $this->makeUser('super_admin');
        $this->makeApplication(['full_name' => 'سارة علي']);

        $response = $this->actingAs($user)->postJson('/api/admin/ambassador-applications/export', [
            'format' => 'xlsx', 'mode' => 'all', 'columns' => ['full_name', 'email'],
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $content = $response->streamedContent();
        // XLSX is a ZIP container — starts with the ZIP local-file-header magic bytes.
        $this->assertSame("PK\x03\x04", substr($content, 0, 4));
    }

    // ── Record-selection modes ────────────────────────────────────────────────

    public function test_selected_mode_exports_only_the_given_ids(): void
    {
        $user = $this->makeUser('super_admin');
        $a = $this->makeApplication(['full_name' => 'Include Me']);
        $this->makeApplication(['full_name' => 'Exclude Me']);

        $response = $this->actingAs($user)->postJson('/api/admin/ambassador-applications/export', [
            'format' => 'csv', 'mode' => 'selected', 'ids' => [$a->id], 'columns' => ['full_name'],
        ]);

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Include Me', $csv);
        $this->assertStringNotContainsString('Exclude Me', $csv);
    }

    public function test_search_mode_matches_the_same_fields_as_the_table_search(): void
    {
        $user = $this->makeUser('super_admin');
        $this->makeApplication(['full_name' => 'Ahmad Match', 'email' => 'match@example.com']);
        $this->makeApplication(['full_name' => 'No Match Here']);

        $response = $this->actingAs($user)->postJson('/api/admin/ambassador-applications/export', [
            'format' => 'csv', 'mode' => 'search', 'search' => 'Ahmad Match', 'columns' => ['full_name'],
        ]);

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Ahmad Match', $csv);
        $this->assertStringNotContainsString('No Match Here', $csv);
    }

    public function test_status_filter_narrows_the_export(): void
    {
        $user = $this->makeUser('super_admin');
        $this->makeApplication(['status' => 'approved', 'full_name' => 'Approved One']);
        $this->makeApplication(['status' => 'rejected', 'full_name' => 'Rejected One']);

        $response = $this->actingAs($user)->postJson('/api/admin/ambassador-applications/export', [
            'format' => 'csv', 'mode' => 'all', 'statuses' => ['approved'], 'columns' => ['full_name'],
        ]);

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Approved One', $csv);
        $this->assertStringNotContainsString('Rejected One', $csv);
    }

    public function test_country_filter_narrows_the_export(): void
    {
        $user = $this->makeUser('super_admin');
        $this->makeApplication(['country' => 'هولندا', 'full_name' => 'Dutch Applicant']);
        $this->makeApplication(['country' => 'اليمن', 'full_name' => 'Yemeni Applicant']);

        $response = $this->actingAs($user)->postJson('/api/admin/ambassador-applications/export', [
            'format' => 'csv', 'mode' => 'all', 'countries' => ['هولندا'], 'columns' => ['full_name'],
        ]);

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Dutch Applicant', $csv);
        $this->assertStringNotContainsString('Yemeni Applicant', $csv);
    }

    public function test_date_range_filter_on_a_chosen_date_field(): void
    {
        $user = $this->makeUser('super_admin');
        $old = $this->makeApplication(['full_name' => 'Old Applicant']);
        $old->forceFill(['created_at' => now()->subYear()])->save();
        $this->makeApplication(['full_name' => 'Recent Applicant']);

        $response = $this->actingAs($user)->postJson('/api/admin/ambassador-applications/export', [
            'format' => 'csv', 'mode' => 'all',
            'date_field' => 'created_at', 'date_from' => now()->subMonth()->toDateString(),
            'columns' => ['full_name'],
        ]);

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Recent Applicant', $csv);
        $this->assertStringNotContainsString('Old Applicant', $csv);
    }

    // ── Draft applications never leak into export (matches existing table behavior) ──

    public function test_draft_applications_are_never_exported(): void
    {
        $user = $this->makeUser('super_admin');
        $this->makeApplication(['is_draft' => true, 'full_name' => 'Draft Applicant']);
        $this->makeApplication(['is_draft' => false, 'full_name' => 'Real Applicant']);

        $response = $this->actingAs($user)->postJson('/api/admin/ambassador-applications/export', [
            'format' => 'csv', 'mode' => 'all', 'columns' => ['full_name'],
        ]);

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Real Applicant', $csv);
        $this->assertStringNotContainsString('Draft Applicant', $csv);
    }

    // ── Preview endpoint ──────────────────────────────────────────────────────

    public function test_preview_returns_record_and_column_counts_without_downloading_a_file(): void
    {
        $user = $this->makeUser('super_admin');
        $this->makeApplication();
        $this->makeApplication();

        $response = $this->actingAs($user)->postJson('/api/admin/ambassador-applications/export/preview', [
            'format' => 'xlsx', 'mode' => 'all', 'columns' => ['full_name', 'email', 'status'],
        ]);

        $response->assertOk();
        $this->assertSame(2, $response->json('data.records'));
        $this->assertSame(3, $response->json('data.columns'));
        $this->assertIsInt($response->json('data.estimated_size_bytes'));
    }

    // ── Invalid columns never leak arbitrary fields ──────────────────────────

    public function test_unknown_column_keys_are_silently_ignored_not_errored(): void
    {
        $user = $this->makeUser('super_admin');
        $this->makeApplication(['full_name' => 'Test Applicant']);

        $response = $this->actingAs($user)->postJson('/api/admin/ambassador-applications/export', [
            'format' => 'csv', 'mode' => 'all', 'columns' => ['full_name', 'not_a_real_column'],
        ]);

        $response->assertOk();
        $this->assertStringContainsString('Test Applicant', $response->streamedContent());
    }

    public function test_export_does_not_modify_any_application_record(): void
    {
        // This is purely a reporting feature — must never touch status/workflow fields.
        $user = $this->makeUser('super_admin');
        $app = $this->makeApplication(['status' => 'new']);

        $this->actingAs($user)->postJson('/api/admin/ambassador-applications/export', [
            'format' => 'csv', 'mode' => 'all', 'columns' => ['full_name', 'status'],
        ])->assertOk();

        $this->assertSame('new', $app->fresh()->status);
        $this->assertDatabaseCount('ambassador_applications', 1);
    }
}
