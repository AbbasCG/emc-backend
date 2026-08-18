<?php

namespace Tests\Feature\Volunteers;

use App\Models\Department;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerHrProfile;
use App\Services\VolunteerApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * EMC Volunteer HR Profile V2 — residence/specialization/bio fields and the
 * two independent publication consents (photo, professional profile), plus
 * their enforcement on the public /api/team endpoint.
 */
class VolunteerHrProfileProfessionalFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function department(): Department
    {
        return Department::create([
            'name_ar' => 'التقنية', 'name_en' => 'Technology', 'name' => 'Technology',
            'slug' => 'tech-' . uniqid(), 'is_active' => true,
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
            'email'       => 'ahmad-' . uniqid() . '@example.com',
            'phone'       => '0501234567',
            'department_id' => $dept->id,
            'job_title'   => 'مطور برمجيات',
            'join_date'   => now()->toDateString(),
            'confirmed'   => true,
            'photo_publication_consent'    => true,
            'professional_profile_consent' => true,
            'cv_file'     => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        ], $overrides);
    }

    // ── 1-6. New fields save correctly, nationality stays separate from residence ──

    public function test_residence_country_saves(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, ['country' => 'هولندا', 'country_code' => 'NL']);

        $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data)->assertStatus(201);

        $this->assertDatabaseHas('volunteer_hr_profiles', ['user_id' => $user->id, 'country' => 'هولندا', 'country_code' => 'NL']);
    }

    public function test_residence_city_saves(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, ['city' => 'Breda']);

        $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data)->assertStatus(201);

        $this->assertDatabaseHas('volunteer_hr_profiles', ['user_id' => $user->id, 'city' => 'Breda']);
    }

    public function test_nationality_remains_separate_from_residence(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, ['nationality' => 'يمنية', 'country' => 'هولندا', 'city' => 'Amsterdam']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(201);
        $this->assertDatabaseHas('volunteer_hr_profiles', [
            'user_id' => $user->id, 'nationality' => 'يمنية', 'country' => 'هولندا', 'city' => 'Amsterdam',
        ]);
        // The two concepts must never collapse onto the same value by accident.
        $profile = VolunteerHrProfile::where('user_id', $user->id)->first();
        $this->assertNotSame($profile->nationality, $profile->country);
    }

    public function test_university_specialization_saves(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, ['university_specialization' => 'هندسة البرمجيات']);

        $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data)->assertStatus(201);

        $this->assertDatabaseHas('volunteer_hr_profiles', ['user_id' => $user->id, 'university_specialization' => 'هندسة البرمجيات']);
    }

    public function test_professional_bio_saves(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $bio = 'مطور برمجيات مهتم بتطوير الأنظمة التعليمية والتحول الرقمي.';
        $data = $this->payload($dept, ['professional_bio' => $bio]);

        $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data)->assertStatus(201);

        $this->assertDatabaseHas('volunteer_hr_profiles', ['user_id' => $user->id, 'professional_bio' => $bio]);
    }

    public function test_bio_max_length_validation(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, ['professional_bio' => str_repeat('أ', 501)]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(422)->assertJsonValidationErrors('professional_bio');
    }

    public function test_bio_html_is_rejected(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, ['professional_bio' => '<script>alert(1)</script>مطور']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(422)->assertJsonValidationErrors('professional_bio');
    }

    // ── 7-9. Consents ─────────────────────────────────────────────────────

    public function test_photo_consent_true_saves_with_timestamp(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, ['photo_publication_consent' => true]);

        $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data)->assertStatus(201);

        $profile = VolunteerHrProfile::where('user_id', $user->id)->first();
        $this->assertTrue($profile->photo_publication_consent);
        $this->assertNotNull($profile->photo_consent_at);
    }

    public function test_photo_consent_false_saves_correctly(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, ['photo_publication_consent' => false]);

        $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data)->assertStatus(201);

        $profile = VolunteerHrProfile::where('user_id', $user->id)->first();
        $this->assertFalse($profile->photo_publication_consent);
    }

    public function test_professional_profile_consent_saves(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, ['professional_profile_consent' => false]);

        $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data)->assertStatus(201);

        $profile = VolunteerHrProfile::where('user_id', $user->id)->first();
        $this->assertFalse($profile->professional_profile_consent);
        $this->assertNotNull($profile->professional_profile_consent_at);
    }

    public function test_consents_are_independent_of_each_other(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, [
            'professional_profile_consent' => true,
            'photo_publication_consent'    => false,
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data)->assertStatus(201);

        $profile = VolunteerHrProfile::where('user_id', $user->id)->first();
        $this->assertTrue($profile->professional_profile_consent);
        $this->assertFalse($profile->photo_publication_consent);
    }

    public function test_missing_photo_consent_is_rejected(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept);
        unset($data['photo_publication_consent']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(422)->assertJsonValidationErrors('photo_publication_consent');
    }

    public function test_missing_professional_profile_consent_is_rejected(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept);
        unset($data['professional_profile_consent']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(422)->assertJsonValidationErrors('professional_profile_consent');
    }

    // ── Live bug regression: consent values arrive as multipart/form-data
    // strings (the real submission path — this form also uploads a CV/photo),
    // not JSON booleans. postJson() above never exercised this path. ──────

    public function test_multipart_string_1_and_0_are_normalized_to_booleans(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, [
            'photo_publication_consent'    => '1',
            'professional_profile_consent' => '0',
        ]);

        $response = $this->actingAs($user, 'sanctum')->post('/api/volunteer/hr-profile', $data);

        $response->assertStatus(201);
        $profile = VolunteerHrProfile::where('user_id', $user->id)->first();
        $this->assertTrue($profile->photo_publication_consent);
        $this->assertFalse($profile->professional_profile_consent);
    }

    public function test_multipart_string_true_and_false_are_normalized_to_booleans(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, [
            'photo_publication_consent'    => 'true',
            'professional_profile_consent' => 'false',
        ]);

        $response = $this->actingAs($user, 'sanctum')->post('/api/volunteer/hr-profile', $data);

        $response->assertStatus(201);
        $profile = VolunteerHrProfile::where('user_id', $user->id)->first();
        $this->assertTrue($profile->photo_publication_consent);
        $this->assertFalse($profile->professional_profile_consent);
    }

    public function test_invalid_consent_string_is_rejected_with_arabic_message(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, ['photo_publication_consent' => 'random']);

        $response = $this->actingAs($user, 'sanctum')->post('/api/volunteer/hr-profile', $data);

        $response->assertStatus(422)->assertJsonValidationErrors('photo_publication_consent');
        $this->assertSame(
            'يرجى اختيار الموافقة أو عدم الموافقة على استخدام الصورة الشخصية',
            $response->json('errors.photo_publication_consent.0'),
        );
    }

    public function test_false_is_not_treated_as_missing_over_multipart(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, [
            'photo_publication_consent'    => '0',
            'professional_profile_consent' => '0',
        ]);

        $response = $this->actingAs($user, 'sanctum')->post('/api/volunteer/hr-profile', $data);

        $response->assertStatus(201);
        $profile = VolunteerHrProfile::where('user_id', $user->id)->first();
        $this->assertFalse($profile->photo_publication_consent);
        $this->assertFalse($profile->professional_profile_consent);
        $this->assertNotNull($profile->photo_consent_at);
        $this->assertNotNull($profile->professional_profile_consent_at);
    }

    public function test_model_returns_real_booleans_not_strings(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $data = $this->payload($dept, [
            'photo_publication_consent'    => '1',
            'professional_profile_consent' => '1',
        ]);

        $this->actingAs($user, 'sanctum')->post('/api/volunteer/hr-profile', $data);

        $profile = VolunteerHrProfile::where('user_id', $user->id)->first();
        $this->assertIsBool($profile->photo_publication_consent);
        $this->assertIsBool($profile->professional_profile_consent);
    }

    // ── 10. Consent fields returned to HR ────────────────────────────────

    public function test_consent_fields_are_returned_to_hr(): void
    {
        Storage::fake('private');
        $dept = $this->department();
        $profile = VolunteerHrProfile::create([
            'full_name' => 'Sara', 'email' => 'sara-' . uniqid() . '@example.com', 'normalized_email' => 'sara@example.com',
            'phone' => '050', 'department_id' => $dept->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'submitted',
            'photo_publication_consent' => true, 'photo_consent_at' => now(),
            'professional_profile_consent' => false, 'professional_profile_consent_at' => now(),
            'university_specialization' => 'علوم الحاسوب', 'professional_bio' => 'نبذة قصيرة',
        ]);

        $hr = $this->hr();
        $response = $this->actingAs($hr, 'sanctum')->getJson("/api/hr/volunteer-hr-profiles/{$profile->id}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertTrue($data['photo_publication_consent']);
        $this->assertNotNull($data['photo_consent_at']);
        $this->assertFalse($data['professional_profile_consent']);
        $this->assertSame('علوم الحاسوب', $data['university_specialization']);
        $this->assertSame('نبذة قصيرة', $data['professional_bio']);
    }

    // ── 11-12. Public /api/team enforcement ──────────────────────────────

    private function approve(VolunteerHrProfile $profile): TeamProfile
    {
        $hr = $this->hr();
        $this->actingAs($hr, 'sanctum')->postJson("/api/hr/volunteer-hr-profiles/{$profile->id}/approve")->assertStatus(200);

        return TeamProfile::where('source_id', $profile->id)->firstOrFail();
    }

    public function test_public_team_endpoint_does_not_expose_photo_without_photo_consent(): void
    {
        Storage::fake('private');
        Storage::fake('public');
        $dept = $this->department();
        $profile = VolunteerHrProfile::create([
            'full_name' => 'NoPhoto', 'email' => 'nophoto@example.com', 'normalized_email' => 'nophoto@example.com',
            'phone' => '050', 'department_id' => $dept->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'submitted',
            'profile_photo_path' => 'volunteer-hr-photos/photo.jpg',
            'photo_publication_consent' => false, 'professional_profile_consent' => true,
        ]);
        $teamProfile = $this->approve($profile);
        $this->assertNull($teamProfile->image);

        $response = $this->getJson('/api/team');

        $response->assertStatus(200);
        $body = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('photo.jpg', $body);
    }

    public function test_public_team_endpoint_exposes_photo_when_consent_given(): void
    {
        Storage::fake('private');
        Storage::fake('public');
        $dept = $this->department();
        $profile = VolunteerHrProfile::create([
            'full_name' => 'WithPhoto', 'email' => 'withphoto@example.com', 'normalized_email' => 'withphoto@example.com',
            'phone' => '050', 'department_id' => $dept->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'submitted',
            'profile_photo_path' => 'volunteer-hr-photos/photo.jpg',
            'photo_publication_consent' => true, 'professional_profile_consent' => true,
        ]);
        $teamProfile = $this->approve($profile);

        $this->assertSame('volunteer-hr-photos/photo.jpg', $teamProfile->image);
    }

    public function test_public_team_endpoint_does_not_expose_bio_without_professional_profile_consent(): void
    {
        Storage::fake('private');
        $dept = $this->department();
        $profile = VolunteerHrProfile::create([
            'full_name' => 'NoBio', 'email' => 'nobio@example.com', 'normalized_email' => 'nobio@example.com',
            'phone' => '050', 'department_id' => $dept->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'submitted',
            'photo_publication_consent' => true, 'professional_profile_consent' => false,
        ]);
        $teamProfile = $this->approve($profile);
        $teamProfile->update(['bio_ar' => 'سرّي جدًا يجب ألا يظهر']);

        $response = $this->getJson('/api/team');

        $response->assertStatus(200);
        $body = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('سرّي جدًا يجب ألا يظهر', $body);
    }

    public function test_directly_added_team_member_without_source_is_unaffected_by_consent_gating(): void
    {
        // A team member entered directly by an admin (super-admin/crud/team)
        // never went through the volunteer consent form — must keep working
        // exactly as before this feature, per the "do not break existing
        // flow" requirement.
        $dept = $this->department();
        TeamProfile::create([
            'department_id' => $dept->id, 'name_ar' => 'مدير مباشر', 'position_ar' => 'مدير',
            'image' => 'team/direct.jpg', 'bio_ar' => 'نبذة مدير مباشر',
            'is_active' => true, 'source' => null,
        ]);

        $response = $this->getJson('/api/team');

        $response->assertStatus(200);
        $body = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('نبذة مدير مباشر', $body);
    }

    // ── 13. Existing profile remains compatible ──────────────────────────

    public function test_existing_profile_predating_this_feature_remains_compatible(): void
    {
        Storage::fake('private');
        $dept = $this->department();
        // No new fields set at all — simulates a row created before this migration.
        $profile = VolunteerHrProfile::create([
            'full_name' => 'Legacy', 'email' => 'legacy2@example.com', 'normalized_email' => 'legacy2@example.com',
            'phone' => '050', 'department_id' => $dept->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'submitted',
        ]);

        $hr = $this->hr();
        $response = $this->actingAs($hr, 'sanctum')->getJson("/api/hr/volunteer-hr-profiles/{$profile->id}");

        $response->assertStatus(200);
        $this->assertNull($response->json('data.photo_publication_consent'));
        $this->assertNull($response->json('data.university_specialization'));

        // Approval must still work for a legacy row with null consents (treated as no-consent).
        $teamProfile = $this->approve($profile);
        $this->assertNull($teamProfile->image);
    }

    // ── 14. Education legacy values remain supported ─────────────────────

    public function test_education_legacy_free_text_value_remains_supported(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        // Old free-text value, not one of the stable enum strings.
        $data = $this->payload($dept, ['education' => 'بكالوريوس هندسة (قيمة نصية قديمة)']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $data);

        $response->assertStatus(201);
        $this->assertSame('بكالوريوس هندسة (قيمة نصية قديمة)', $response->json('data.education'));
    }
}
