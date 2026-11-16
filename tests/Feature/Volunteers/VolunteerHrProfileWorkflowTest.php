<?php

namespace Tests\Feature\Volunteers;

use App\Models\Department;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerHrProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VolunteerHrProfileWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MakesCvFiles;

    protected function setUp(): void
    {
        parent::setUp();
        // The legacy HR-profile pipeline is switched off by default (cancelled path); these tests prove its legacy code, so turn it on.
        config(['volunteer_onboarding.hr_profile_pipeline_enabled' => true]);
    }

    private function department(): Department
    {
        return Department::create([
            'name_ar' => 'التقنية', 'name' => 'Technology', 'slug' => 'tech-' . uniqid(), 'is_active' => true,
        ]);
    }

    private function volunteer(): User
    {
        return User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    private function hr(): User
    {
        return User::factory()->create(['role' => 'hr_manager', 'is_active' => true]);
    }

    private function payload(Department $dept, array $overrides = []): array
    {
        return array_merge([
            'full_name'   => 'أحمد محمد',
            'email'       => 'ahmad@example.com',
            'phone'       => '0501234567',
            'department_id' => $dept->id,
            'job_title'   => 'مطور برمجيات',
            'join_date'   => now()->toDateString(),
            'confirmed'   => true,
            'photo_publication_consent'    => true,
            'professional_profile_consent' => true,
            'cv_file'     => $this->pdfCv('cv.pdf', 100),
        ], $overrides);
    }

    public function test_volunteer_submits_complete_hr_profile(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept));

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertDatabaseHas('volunteer_hr_profiles', ['user_id' => $user->id, 'status' => 'submitted']);
    }

    public function test_cv_must_be_a_real_pdf_word_images_and_renamed_files_are_refused(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();

        foreach ([$this->docxCv('cv.docx'), $this->pngCv('cv.png'), $this->renamedExecutable('cv.pdf')] as $file) {
            $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept, ['cv_file' => $file]))
                ->assertStatus(422)->assertJsonValidationErrors('cv_file');
        }
        $this->assertDatabaseMissing('volunteer_hr_profiles', ['user_id' => $user->id]);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_an_accepted_cv_is_stored_privately_as_a_pdf_under_a_server_made_name(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();

        $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($this->department(), ['cv_file' => $this->pdfCv('../x y.pdf')]))->assertStatus(201);

        $path = \App\Models\VolunteerHrProfile::where('user_id', $user->id)->value('cv_path');
        $this->assertMatchesRegularExpression('#^volunteer-hr-cvs/[A-Za-z0-9]{40}\.pdf$#', $path);
        Storage::disk('private')->assertExists($path);
    }

    public function test_cv_is_required(): void
    {
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept);
        unset($data['cv_file']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(422)->assertJsonValidationErrors('cv_file');
    }

    public function test_job_title_is_required(): void
    {
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept);
        unset($data['job_title']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(422)->assertJsonValidationErrors('job_title');
    }

    public function test_duplicate_approved_email_is_rejected(): void
    {
        Storage::fake('private');
        $dept = $this->department();
        $existingUser = $this->volunteer();
        VolunteerHrProfile::create([
            'user_id' => $existingUser->id, 'full_name' => 'Existing', 'email' => 'dup@example.com',
            'normalized_email' => 'dup@example.com', 'phone' => '0500000000', 'department_id' => $dept->id,
            'job_title' => 'X', 'join_date' => now(), 'cv_path' => 'x.pdf', 'status' => 'approved',
        ]);

        $newUser = $this->volunteer();
        $response = $this->actingAs($newUser, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept, ['email' => 'dup@example.com']));

        $response->assertStatus(422)->assertJson(['code' => 'already_volunteer']);
    }

    public function test_duplicate_pending_profile_is_rejected(): void
    {
        Storage::fake('private');
        $dept = $this->department();
        $existingUser = $this->volunteer();
        VolunteerHrProfile::create([
            'user_id' => $existingUser->id, 'full_name' => 'Existing', 'email' => 'pending@example.com',
            'normalized_email' => 'pending@example.com', 'phone' => '0500000000', 'department_id' => $dept->id,
            'job_title' => 'X', 'join_date' => now(), 'cv_path' => 'x.pdf', 'status' => 'submitted',
        ]);

        $newUser = $this->volunteer();
        $response = $this->actingAs($newUser, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept, ['email' => 'pending@example.com']));

        $response->assertStatus(422)->assertJson(['code' => 'pending_exists']);
    }

    public function test_hr_can_list_profiles(): void
    {
        $hr = $this->hr();
        $response = $this->actingAs($hr, 'sanctum')->getJson('/api/hr/volunteer-hr-profiles');
        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_unauthorized_role_cannot_list(): void
    {
        $student = $this->volunteer();
        $response = $this->actingAs($student, 'sanctum')->getJson('/api/hr/volunteer-hr-profiles');
        $response->assertStatus(403);
    }

    private function profileWithCv(array $overrides = []): VolunteerHrProfile
    {
        $dept = $this->department();
        Storage::disk('private')->put('volunteer-hr-cvs/x.pdf', '%PDF-1.4 fake-pdf-bytes');

        return VolunteerHrProfile::create(array_merge([
            'full_name' => 'A', 'email' => 'a@a.com', 'normalized_email' => 'a@a.com', 'phone' => '050',
            'department_id' => $dept->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'volunteer-hr-cvs/x.pdf', 'cv_original_name' => 'my-cv.pdf', 'cv_mime_type' => 'application/pdf', 'cv_size' => 23,
            'status' => 'submitted',
        ], $overrides));
    }

    public function test_authorized_hr_can_preview_pdf_cv(): void
    {
        Storage::fake('private');
        $profile = $this->profileWithCv();
        $hr = $this->hr();

        $response = $this->actingAs($hr, 'sanctum')->get("/api/hr/volunteers/{$profile->id}/cv/preview");

        $response->assertStatus(200);
    }

    // Privacy policy: no CV download endpoint exists — preview only.
    public function test_cv_download_route_no_longer_exists(): void
    {
        Storage::fake('private');
        $profile = $this->profileWithCv();
        $hr = $this->hr();

        $this->actingAs($hr, 'sanctum')
            ->get("/api/hr/volunteers/{$profile->id}/cv/download")
            ->assertStatus(404);
    }

    public function test_unauthorized_role_receives_403_from_cv_preview_endpoint(): void
    {
        Storage::fake('private');
        $profile = $this->profileWithCv();
        $student = $this->volunteer();

        $this->actingAs($student, 'sanctum')->get("/api/hr/volunteers/{$profile->id}/cv/preview")->assertStatus(403);
    }

    public function test_volunteer_cannot_preview_another_volunteers_cv(): void
    {
        Storage::fake('private');
        $profile = $this->profileWithCv();
        $otherVolunteer = $this->volunteer();

        // No self-service CV route exists for volunteers at all — a
        // non-HR-role user hitting the HR-only endpoint is rejected outright,
        // never reaching a point where profile ownership would matter.
        $response = $this->actingAs($otherVolunteer, 'sanctum')->get("/api/hr/volunteers/{$profile->id}/cv/preview");
        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_receives_401_or_redirect_from_cv_endpoints(): void
    {
        Storage::fake('private');
        $profile = $this->profileWithCv();

        $response = $this->getJson("/api/hr/volunteers/{$profile->id}/cv/preview");

        $response->assertStatus(401);
    }

    public function test_hr_approval_creates_team_profile_in_correct_department(): void
    {
        Storage::fake('private');
        $dept = $this->department();
        $volunteerUser = $this->volunteer();
        $profile = VolunteerHrProfile::create([
            'user_id' => $volunteerUser->id, 'full_name' => 'Sara', 'email' => 'sara@example.com',
            'normalized_email' => 'sara@example.com', 'phone' => '050', 'department_id' => $dept->id,
            'job_title' => 'مصممة جرافيك', 'join_date' => now(), 'cv_path' => 'volunteer-hr-cvs/sara.pdf',
            'status' => 'submitted', 'submitted_at' => now(),
        ]);

        $hr = $this->hr();
        $response = $this->actingAs($hr, 'sanctum')->postJson("/api/hr/volunteer-hr-profiles/{$profile->id}/approve");

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseHas('volunteer_hr_profiles', ['id' => $profile->id, 'status' => 'approved']);
        $this->assertDatabaseHas('team_profiles', [
            'source' => 'volunteer_hr_profile', 'source_id' => $profile->id,
            'department_id' => $dept->id, 'position_ar' => 'مصممة جرافيك', 'user_id' => $volunteerUser->id,
        ]);
    }

    public function test_approved_person_appears_in_team_and_members_queries(): void
    {
        Storage::fake('private');
        $dept = $this->department();
        $profile = VolunteerHrProfile::create([
            'full_name' => 'Omar', 'email' => 'omar@example.com', 'normalized_email' => 'omar@example.com',
            'phone' => '050', 'department_id' => $dept->id, 'job_title' => 'دعم فني', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'submitted',
        ]);

        $hr = $this->hr();
        $this->actingAs($hr, 'sanctum')->postJson("/api/hr/volunteer-hr-profiles/{$profile->id}/approve")->assertStatus(200);

        // Team page query: AdminTeamProfileController groups by department -> teamProfiles.
        $teamProfile = TeamProfile::where('source_id', $profile->id)->first();
        $this->assertNotNull($teamProfile);
        $this->assertTrue(Department::find($dept->id)->teamProfiles()->where('id', $teamProfile->id)->exists());

        // Members page query: AdminMemberController reads TeamProfile directly — same row.
        $this->assertTrue(TeamProfile::where('id', $teamProfile->id)->exists());
    }

    public function test_approval_does_not_create_duplicate_rows_and_is_idempotent(): void
    {
        Storage::fake('private');
        $dept = $this->department();
        $profile = VolunteerHrProfile::create([
            'full_name' => 'Lina', 'email' => 'lina@example.com', 'normalized_email' => 'lina@example.com',
            'phone' => '050', 'department_id' => $dept->id, 'job_title' => 'منسقة فعاليات', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'submitted',
        ]);

        $hr = $this->hr();
        $this->actingAs($hr, 'sanctum')->postJson("/api/hr/volunteer-hr-profiles/{$profile->id}/approve")->assertStatus(200);
        $this->assertSame(1, TeamProfile::where('source_id', $profile->id)->count());

        // Second approval call — idempotent, no second team_profiles row.
        $this->actingAs($hr, 'sanctum')->postJson("/api/hr/volunteer-hr-profiles/{$profile->id}/approve")->assertStatus(200);
        $this->assertSame(1, TeamProfile::where('source_id', $profile->id)->count());
    }

    public function test_rejection_requires_a_reason(): void
    {
        Storage::fake('private');
        $dept = $this->department();
        $profile = VolunteerHrProfile::create([
            'full_name' => 'Yusuf', 'email' => 'yusuf@example.com', 'normalized_email' => 'yusuf@example.com',
            'phone' => '050', 'department_id' => $dept->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'submitted',
        ]);

        $hr = $this->hr();
        $response = $this->actingAs($hr, 'sanctum')->postJson("/api/hr/volunteer-hr-profiles/{$profile->id}/reject", []);
        $response->assertStatus(422)->assertJsonValidationErrors('reason');

        $response = $this->actingAs($hr, 'sanctum')->postJson("/api/hr/volunteer-hr-profiles/{$profile->id}/reject", ['reason' => 'CV غير مكتملة']);
        $response->assertStatus(200);
        $this->assertDatabaseHas('volunteer_hr_profiles', ['id' => $profile->id, 'status' => 'rejected', 'rejection_reason' => 'CV غير مكتملة']);
        $this->assertDatabaseMissing('team_profiles', ['source_id' => $profile->id]);
    }

    public function test_emergency_contact_fields_are_no_longer_required(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept);
        // Deliberately not sending emergency_contact_name/phone at all.

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(201);
    }

    public function test_languages_array_is_accepted_and_normalized(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, ['languages' => ['العربية', 'الإنجليزية']]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(201);
        $languages = $response->json('data.languages');
        sort($languages);
        $this->assertSame(['الإنجليزية', 'العربية'], $languages);
    }

    public function test_invalid_language_format_is_rejected(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, ['languages' => 'not-an-array']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(422)->assertJsonValidationErrors('languages');
    }

    public function test_duplicate_language_in_same_request_is_rejected(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept);
        unset($data['languages']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', array_merge($data, [
            'languages' => ['العربية', 'العربية'],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('languages.1');
    }

    public function test_department_is_required(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept);
        unset($data['department_id']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(422)->assertJsonValidationErrors('department_id');
    }

    public function test_canonical_job_title_must_belong_to_selected_department(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $deptA = \App\Models\Department::create(['name_ar' => 'قسم أ', 'name' => 'A', 'slug' => 'a-' . uniqid(), 'is_active' => true]);
        $deptB = \App\Models\Department::create(['name_ar' => 'قسم ب', 'name' => 'B', 'slug' => 'b-' . uniqid(), 'is_active' => true]);
        \App\Models\JobTitle::create(['department_id' => $deptB->id, 'name_ar' => 'مسمى قسم ب فقط', 'is_active' => true]);

        $data = $this->payload($deptA, ['job_title' => 'مسمى قسم ب فقط', 'job_title_source' => 'canonical']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(422)->assertJsonValidationErrors('job_title');
        $this->assertSame('المسمى الوظيفي المحدد لا يتبع القسم المختار', $response->json('errors.job_title.0'));
    }

    public function test_canonical_job_title_belonging_to_the_selected_department_is_accepted(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = \App\Models\Department::create(['name_ar' => 'قسم ج', 'name' => 'C', 'slug' => 'c-' . uniqid(), 'is_active' => true]);
        \App\Models\JobTitle::create(['department_id' => $dept->id, 'name_ar' => 'مسمى صحيح', 'is_active' => true]);

        $data = $this->payload($dept, ['job_title' => 'مسمى صحيح', 'job_title_source' => 'canonical']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(201);
    }

    public function test_custom_job_title_source_bypasses_department_fk_check(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, ['job_title' => 'مسمى حر غير موجود بالقائمة', 'job_title_source' => 'custom']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(201);
    }

    public function test_existing_profile_with_legacy_free_text_languages_still_renders_safely(): void
    {
        Storage::fake('private');
        $dept = $this->department();
        $profile = \App\Models\VolunteerHrProfile::create([
            'full_name' => 'Legacy', 'email' => 'legacy@example.com', 'normalized_email' => 'legacy@example.com',
            'phone' => '050', 'department_id' => $dept->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'submitted', 'languages' => 'العربية, الإنجليزية',
        ]);

        $hr = $this->hr();
        $response = $this->actingAs($hr, 'sanctum')->getJson("/api/hr/volunteer-hr-profiles/{$profile->id}");

        $response->assertStatus(200);
        $this->assertSame(['العربية', 'الإنجليزية'], $response->json('data.languages'));
    }

    public function test_job_titles_endpoint_returns_only_the_selected_departments_titles(): void
    {
        $user = $this->volunteer();
        $deptA = \App\Models\Department::create(['name_ar' => 'قسم د', 'name' => 'D', 'slug' => 'd-' . uniqid(), 'is_active' => true]);
        $deptB = \App\Models\Department::create(['name_ar' => 'قسم هـ', 'name' => 'E', 'slug' => 'e-' . uniqid(), 'is_active' => true]);
        \App\Models\JobTitle::create(['department_id' => $deptA->id, 'name_ar' => 'مسمى د', 'is_active' => true]);
        \App\Models\JobTitle::create(['department_id' => $deptB->id, 'name_ar' => 'مسمى هـ', 'is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/job-titles?department_id={$deptA->id}");

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('مسمى د'));
        $this->assertFalse($names->contains('مسمى هـ'));
    }

    public function test_approval_workflow_remains_unchanged_after_these_changes(): void
    {
        Storage::fake('private');
        $dept = $this->department();
        $profile = \App\Models\VolunteerHrProfile::create([
            'full_name' => 'Regr', 'email' => 'regr@example.com', 'normalized_email' => 'regr@example.com',
            'phone' => '050', 'department_id' => $dept->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'submitted', 'languages' => ['العربية'],
        ]);

        $hr = $this->hr();
        $response = $this->actingAs($hr, 'sanctum')->postJson("/api/hr/volunteer-hr-profiles/{$profile->id}/approve");

        $response->assertStatus(200);
        $this->assertDatabaseHas('team_profiles', ['source_id' => $profile->id, 'department_id' => $dept->id]);
    }

    public function test_raw_cv_path_is_not_exposed_in_api_response(): void
    {
        Storage::fake('private');
        $dept = $this->department();
        $profile = VolunteerHrProfile::create([
            'full_name' => 'Huda', 'email' => 'huda@example.com', 'normalized_email' => 'huda@example.com',
            'phone' => '050', 'department_id' => $dept->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'volunteer-hr-cvs/secret-path.pdf', 'status' => 'submitted',
        ]);

        $hr = $this->hr();
        $response = $this->actingAs($hr, 'sanctum')->getJson("/api/hr/volunteer-hr-profiles/{$profile->id}");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertStringNotContainsString('secret-path.pdf', json_encode($body));
        $this->assertTrue($body['data']['cv']['available']);
    }

    public function test_detail_api_returns_all_stored_volunteer_fields(): void
    {
        Storage::fake('private');
        $dept = $this->department();
        $profile = VolunteerHrProfile::create([
            'full_name' => 'Kamal', 'email' => 'kamal@example.com', 'normalized_email' => 'kamal@example.com',
            'phone' => '+31612345678', 'phone_country_code' => '+31', 'country' => 'هولندا', 'country_code' => 'NL',
            'city' => 'أمستردام', 'nationality' => 'هولندي', 'gender' => 'male', 'date_of_birth' => '1995-01-01',
            'department_id' => $dept->id, 'job_title' => 'مطور برمجيات', 'employment_type' => 'volunteer',
            'join_date' => now(), 'availability' => 'مساءً', 'weekly_hours' => 5,
            'skills' => 'PHP, React', 'languages' => ['العربية', 'الإنجليزية'], 'education' => 'بكالوريوس',
            'experience' => '3 سنوات', 'motivation' => 'حب العطاء', 'linkedin_url' => 'https://linkedin.com/in/kamal',
            'portfolio_url' => 'https://kamal.dev',
            'cv_path' => 'volunteer-hr-cvs/kamal.pdf', 'cv_original_name' => 'Kamal-CV.pdf', 'cv_mime_type' => 'application/pdf', 'cv_size' => 12345,
            'status' => 'submitted',
        ]);

        $hr = $this->hr();
        $response = $this->actingAs($hr, 'sanctum')->getJson("/api/hr/volunteer-hr-profiles/{$profile->id}");

        $response->assertStatus(200);
        $data = $response->json('data');
        foreach (['full_name', 'email', 'phone', 'phone_country_code', 'country', 'country_code', 'city',
            'nationality', 'gender', 'date_of_birth', 'department_id', 'job_title', 'employment_type',
            'join_date', 'availability', 'weekly_hours', 'skills', 'languages', 'education', 'experience',
            'motivation', 'linkedin_url', 'portfolio_url', 'status'] as $field) {
            $this->assertArrayHasKey($field, $data, "Missing field: {$field}");
        }
        $this->assertSame('+31', $data['phone_country_code']);
        $this->assertSame('NL', $data['country_code']);
        $this->assertSame('Kamal-CV.pdf', $data['cv']['file_name']);
        $this->assertSame('application/pdf', $data['cv']['mime_type']);
        $this->assertSame(12345, $data['cv']['size']);
    }

    public function test_country_code_and_calling_code_are_saved(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, [
            'country' => 'هولندا', 'country_code' => 'NL', 'phone' => '+31612345678', 'phone_country_code' => '+31',
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(201);
        $this->assertDatabaseHas('volunteer_hr_profiles', [
            'user_id' => $user->id, 'country_code' => 'NL', 'phone_country_code' => '+31', 'phone' => '+31612345678',
        ]);
    }

    public function test_existing_old_phone_and_country_records_remain_readable(): void
    {
        Storage::fake('private');
        $dept = $this->department();
        // Pre-dates country_code/phone_country_code — both null, only free-text values.
        $profile = VolunteerHrProfile::create([
            'full_name' => 'Old', 'email' => 'old@example.com', 'normalized_email' => 'old@example.com',
            'phone' => '0501234567', 'country' => 'اليمن', 'department_id' => $dept->id, 'job_title' => 'X',
            'join_date' => now(), 'cv_path' => 'x.pdf', 'status' => 'submitted',
        ]);

        $hr = $this->hr();
        $response = $this->actingAs($hr, 'sanctum')->getJson("/api/hr/volunteer-hr-profiles/{$profile->id}");

        $response->assertStatus(200);
        $this->assertSame('0501234567', $response->json('data.phone'));
        $this->assertSame('اليمن', $response->json('data.country'));
        $this->assertNull($response->json('data.country_code'));
    }

    public function test_detail_endpoint_eager_loads_relations_without_nplus1(): void
    {
        Storage::fake('private');
        $dept = $this->department();
        $reviewer = $this->hr();
        $profile = VolunteerHrProfile::create([
            'full_name' => 'Query', 'email' => 'query@example.com', 'normalized_email' => 'query@example.com',
            'phone' => '050', 'department_id' => $dept->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'under_review', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(),
        ]);

        $hr = $this->hr();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->actingAs($hr, 'sanctum')->getJson("/api/hr/volunteer-hr-profiles/{$profile->id}")->assertStatus(200);
        $queryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // A handful of fixed queries (profile + eager-loaded relations), not
        // one-per-relation-per-row growth — generous bound, not an exact count.
        $this->assertLessThan(10, $queryCount);
    }

    // ── Post-submit immediate-confirmation regression (blank-page bug) ──────

    public function test_submit_endpoint_returns_the_canonical_profile_resource_including_department(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept));

        $response->assertStatus(201);
        // Before the eager-load fix, `department` was an omitted key (whenLoaded
        // on a non-eager-loaded relation), not merely null — assertJsonStructure
        // fails either way if the key is genuinely absent.
        $response->assertJsonStructure(['data' => ['id', 'status', 'submitted_at', 'department' => ['id', 'name'], 'job_title']]);
        $this->assertSame($dept->id, $response->json('data.department.id'));
    }

    public function test_submit_response_includes_status_and_submitted_at(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept));

        $response->assertStatus(201)->assertJson(['data' => ['status' => 'submitted']]);
        $this->assertNotNull($response->json('data.submitted_at'));
    }

    public function test_duplicate_submission_does_not_create_a_second_profile_and_returns_a_controlled_response(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();

        $first = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept));
        $first->assertStatus(201);

        $second = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept));

        // Controlled 422 with a code the frontend can key off — never a raw 500.
        $second->assertStatus(422);
        $this->assertContains($second->json('code'), ['pending_exists', 'already_volunteer']);
        $this->assertSame(1, VolunteerHrProfile::where('user_id', $user->id)->count());
    }

    public function test_resubmitting_after_a_soft_deleted_prior_profile_does_not_crash(): void
    {
        // Root cause of the raw-500 crash this ticket fixes: `user_id` was
        // unique at the DB level, but SoftDeletes means a soft-deleted row
        // still occupies that slot — MySQL has no filtered unique index, so
        // the next legitimate submission's INSERT violated the constraint.
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();

        $existing = VolunteerHrProfile::create([
            'user_id' => $user->id, 'full_name' => 'Old', 'email' => 'old@example.com',
            'normalized_email' => 'old@example.com', 'phone' => '050', 'department_id' => $dept->id,
            'job_title' => 'X', 'join_date' => now(), 'cv_path' => 'x.pdf', 'status' => 'rejected',
        ]);
        $existing->delete(); // soft delete

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept));

        $response->assertStatus(201);
    }

    public function test_existing_draft_transitions_to_submitted_on_resubmit_via_update(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $rejected = VolunteerHrProfile::create([
            'user_id' => $user->id, 'full_name' => 'Retry', 'email' => 'retry@example.com',
            'normalized_email' => 'retry@example.com', 'phone' => '050', 'department_id' => $dept->id,
            'job_title' => 'X', 'join_date' => now(), 'cv_path' => 'x.pdf', 'status' => 'rejected',
        ]);

        // POST + _method spoofing (not putJson) — payload() carries an
        // UploadedFile, and PHP has no native multipart-PUT support.
        $data = array_merge($this->payload($dept, ['email' => 'retry@example.com']), ['_method' => 'PUT']);
        $response = $this->actingAs($user, 'sanctum')->post("/api/volunteer/hr-profile/{$rejected->id}", $data);

        $response->assertStatus(200)->assertJson(['data' => ['status' => 'submitted']]);
        $this->assertSame(1, VolunteerHrProfile::where('user_id', $user->id)->count());
    }

    public function test_raw_cv_path_is_not_exposed_after_submit(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept));

        $response->assertStatus(201);
        $this->assertArrayNotHasKey('cv_path', $response->json('data'));
        $this->assertTrue($response->json('data.cv.available'));
    }

    public function test_authorization_after_submit_response_change_remains_correct(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $other = $this->volunteer();
        $dept = $this->department();

        $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept))->assertStatus(201);

        // Another volunteer's own GET must never see the first user's profile.
        $mine = $this->actingAs($other, 'sanctum')->getJson('/api/volunteer/hr-profile');
        $mine->assertStatus(200)->assertJson(['data' => null]);
    }

    public function test_any_authenticated_user_can_load_department_options_for_the_form(): void
    {
        $this->department();
        $volunteer = $this->volunteer();

        // Regression: this previously reused the HR-only filter-options
        // endpoint, silently 403'ing the department dropdown for anyone
        // who isn't hr_manager/admin/super_admin/tech_admin.
        $response = $this->actingAs($volunteer, 'sanctum')->getJson('/api/departments/options');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
    }
}
