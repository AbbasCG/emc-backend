<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\PlatformNotification;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class VolunteerRequestTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MakesCvFiles;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name'          => 'Ahmed Al-Rashidi',
            'email'              => 'ahmed@example.com',
            'phone'              => '+9665012345678',
            'country'            => 'Saudi Arabia',
            'city'               => 'Riyadh',
            'gender'             => 'male',
            'desired_department' => 'Education',
            'experience_level'   => 'intermediate',
            'skills'             => 'Teaching, Arabic Language',
            'availability'       => 'weekends',
            'motivation'         => 'I want to contribute to community education and make a difference.',
            'agree_terms'        => true,
        ], $overrides);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    // ─── Public submission ────────────────────────────────────────────────────

    public function test_public_user_can_submit_volunteer_request(): void
    {
        $response = $this->postJson('/api/volunteer-requests', $this->validPayload());

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.full_name', 'Ahmed Al-Rashidi')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('volunteer_requests', [
            'email'  => 'ahmed@example.com',
            'status' => 'pending',
        ]);
    }

    public function test_missing_required_fields_returns_validation_errors(): void
    {
        $response = $this->postJson('/api/volunteer-requests', []);

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonStructure(['errors' => [
                'full_name',
                'email',
                'phone',
                'country',
                'city',
                'desired_department',
                'agree_terms',
            ]]);

        // gender, experience_level, availability and motivation became optional with the five-level assessment form
        // (commit b23f39b): the wizard sends education_level / volunteer_days_availability / value_add_contributions instead.
        foreach (['gender', 'experience_level', 'availability', 'motivation'] as $optional) {
            $this->assertArrayNotHasKey($optional, $response->json('errors'), "$optional is no longer a required field");
        }
    }

    public function test_assessment_submission_without_the_legacy_fields_falls_back_to_the_new_answers(): void
    {
        $payload = $this->validPayload([
            'education_level' => 'bachelor',
            'volunteer_days_availability' => 'weekends',
            'value_add_contributions' => 'Short',
        ]);
        unset($payload['gender'], $payload['experience_level'], $payload['availability'], $payload['motivation']);

        $this->postJson('/api/volunteer-requests', $payload)->assertCreated();

        $this->assertDatabaseHas('volunteer_requests', [
            'email' => 'ahmed@example.com',
            'gender' => 'unspecified',
            'experience_level' => 'bachelor',
            'availability' => 'weekends',
            'motivation' => 'Short',
        ]);
    }

    public function test_the_motivation_has_no_server_side_minimum_because_the_wizard_forwards_a_free_text_answer_as_it(): void
    {
        // PENDING A BUSINESS DECISION: the old rule was `required|min:20`. VolunteerApply.tsx sends the optional, unbounded
        // `value_add_contributions` as `motivation`, so a minimum here would reject valid wizard submissions. The legacy
        // opportunity form still enforces 20 characters in the browser only.
        $this->postJson('/api/volunteer-requests', $this->validPayload(['motivation' => 'Too short']))->assertCreated();
    }

    public function test_invalid_cv_file_type_is_rejected(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/volunteer-requests', $this->validPayload([
            'cv_file' => UploadedFile::fake()->create('malware.exe', 100, 'application/octet-stream'),
        ]));

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['cv_file']]);
    }

    public function test_valid_pdf_cv_is_stored_with_safe_name(): void
    {
        Storage::fake('private');

        $response = $this->postJson('/api/volunteer-requests', $this->validPayload([
            'cv_file' => $this->pdfCv('my_cv.pdf', 500),
        ]));

        $response->assertCreated();

        $row = VolunteerRequest::where('email', 'ahmed@example.com')->firstOrFail();
        $this->assertNotNull($row->cv_file_path);
        // filename must be a UUID-based safe name, not the original
        $this->assertStringNotContainsString('my_cv', $row->cv_file_path);
        $this->assertStringStartsWith('volunteer-cvs/cv_', $row->cv_file_path);
        Storage::disk('private')->assertExists($row->cv_file_path);
    }

    public function test_agree_terms_must_be_accepted(): void
    {
        $response = $this->postJson('/api/volunteer-requests', $this->validPayload([
            'agree_terms' => false,
        ]));

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['agree_terms']]);
    }

    // ─── Notifications ───────────────────────────────────────────────────────

    public function test_volunteer_request_creates_notifications_for_super_admin_admin_and_hr(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $admin      = User::factory()->create(['role' => 'admin',       'is_active' => true]);
        $hrManager  = User::factory()->create(['role' => 'hr_manager',  'is_active' => true]);
        $student    = User::factory()->create(['role' => 'student',     'is_active' => true]);

        $this->postJson('/api/volunteer-requests', $this->validPayload())
            ->assertCreated();

        foreach ([$superAdmin->id, $admin->id, $hrManager->id] as $uid) {
            $this->assertDatabaseHas('platform_notifications', [
                'user_id' => $uid,
                'type'    => 'volunteer_request',
            ]);
        }

        $this->assertDatabaseMissing('platform_notifications', [
            'user_id' => $student->id,
            'type'    => 'volunteer_request',
        ]);
    }

    public function test_volunteer_request_notification_targets_department_specific_role(): void
    {
        $marketingManager = User::factory()->create(['role' => 'marketing_manager', 'is_active' => true]);
        $financeManager   = User::factory()->create(['role' => 'finance_manager',   'is_active' => true]);

        // Submit for marketing department
        $this->postJson('/api/volunteer-requests', $this->validPayload([
            'email'              => 'mkt@example.com',
            'desired_department' => 'التسويق والإعلام',
        ]))->assertCreated();

        // Marketing manager gets notified
        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $marketingManager->id,
            'type'    => 'volunteer_request',
        ]);

        // Finance manager does NOT get this specific notification
        $this->assertDatabaseMissing('platform_notifications', [
            'user_id' => $financeManager->id,
            'type'    => 'volunteer_request',
        ]);
    }

    public function test_volunteer_notification_contains_correct_content(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->postJson('/api/volunteer-requests', $this->validPayload([
            'full_name'          => 'Nora Al-Salem',
            'email'              => 'nora@example.com',
            'desired_department' => 'المالية',
        ]))->assertCreated();

        $notification = PlatformNotification::where('user_id', $superAdmin->id)
            ->where('type', 'volunteer_request')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('Nora Al-Salem', $notification->message);
        $this->assertStringContainsString('المالية', $notification->message);
        $this->assertStringContainsString('volunteer-requests', $notification->action_url);
    }

    // ─── Admin list ───────────────────────────────────────────────────────────

    public function test_admin_can_list_volunteer_requests(): void
    {
        $admin = $this->makeAdmin();

        VolunteerRequest::create($this->validPayload(['agree_terms' => null]) + ['status' => 'pending']);
        VolunteerRequest::create($this->validPayload(['email' => 'b@example.com', 'agree_terms' => null]) + ['status' => 'accepted']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/volunteer-requests')
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data', 'meta', 'statistics']);
    }

    public function test_admin_list_filters_by_status(): void
    {
        $admin = $this->makeAdmin();

        VolunteerRequest::create(array_merge($this->validPayload(['agree_terms' => null]), ['status' => 'pending',  'full_name' => 'Pending Person']));
        VolunteerRequest::create(array_merge($this->validPayload(['agree_terms' => null]), ['status' => 'accepted', 'full_name' => 'Accepted Person', 'email' => 'acc@x.com']));

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/volunteer-requests?status=accepted')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('full_name')->all();
        $this->assertContains('Accepted Person', $names);
        $this->assertNotContains('Pending Person', $names);
    }

    public function test_admin_list_search_by_name_or_email(): void
    {
        $admin = $this->makeAdmin();
        VolunteerRequest::create(array_merge($this->validPayload(['agree_terms' => null]), ['full_name' => 'Unique Searcher', 'email' => 'unique@x.com']));

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/volunteer-requests?search=unique@x.com')
            ->assertOk()
            ->assertJsonFragment(['full_name' => 'Unique Searcher']);
    }

    // ─── Admin show ───────────────────────────────────────────────────────────

    public function test_admin_can_view_single_volunteer_request(): void
    {
        $admin = $this->makeAdmin();
        $vr    = VolunteerRequest::create(array_merge($this->validPayload(['agree_terms' => null]), ['status' => 'pending']));

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/volunteer-requests/{$vr->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $vr->id)
            ->assertJsonPath('data.email', 'ahmed@example.com');
    }

    // ─── Admin status update ──────────────────────────────────────────────────

    public function test_admin_can_update_volunteer_request_status(): void
    {
        $admin = $this->makeAdmin();
        $vr    = VolunteerRequest::create(array_merge($this->validPayload(['agree_terms' => null]), ['status' => 'pending']));

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/volunteer-requests/{$vr->id}/status", [
                'status'      => 'accepted',
                'admin_notes' => 'Great candidate.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.admin_notes', 'Great candidate.');

        $row = $vr->fresh();
        $this->assertSame('accepted', $row->status);
        $this->assertSame($admin->id, $row->reviewed_by);
        $this->assertNotNull($row->reviewed_at);
    }

    public function test_admin_can_set_reviewing_status(): void
    {
        $admin = $this->makeAdmin();
        $vr    = VolunteerRequest::create(array_merge($this->validPayload(['agree_terms' => null]), ['status' => 'pending']));

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/volunteer-requests/{$vr->id}/status", ['status' => 'reviewing'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reviewing');
    }

    public function test_admin_list_includes_cv_url_and_required_fields(): void
    {
        Storage::fake('public');

        $admin = $this->makeAdmin();
        $path  = 'volunteer-cvs/cv_test.pdf';
        Storage::disk('public')->put($path, 'dummy pdf');

        VolunteerRequest::create(array_merge($this->validPayload(['agree_terms' => null]), [
            'status'       => 'pending',
            'cv_file_path' => $path,
            'admin_notes'  => 'Needs follow-up',
        ]));

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/volunteer-requests')
            ->assertOk();

        $row = collect($response->json('data'))->first();
        $this->assertNotNull($row);
        $this->assertArrayHasKey('cv_url', $row);
        $this->assertArrayHasKey('cv_file_url', $row);
        $this->assertArrayHasKey('cv_view_url', $row);
        $this->assertNotNull($row['cv_url']);
        $this->assertSame($row['cv_url'], $row['cv_file_url']);
        $this->assertSame($row['cv_url'], $row['cv_view_url']);
        $this->assertSame('Needs follow-up', $row['admin_notes']);
    }

    // Privacy policy: no CV download — only a signed inline preview/view route
    // exists (admin.volunteer-requests.cv.view). The plain admin.volunteer-requests.cv
    // (download) route was removed.
    public function test_signed_cv_view_works_for_admin_issued_url(): void
    {
        Storage::fake('public');

        $admin = $this->makeAdmin();
        $path  = 'volunteer-cvs/cv_signed.pdf';
        Storage::disk('public')->put($path, 'signed pdf content');

        $vr = VolunteerRequest::create(array_merge($this->validPayload(['agree_terms' => null]), [
            'status'       => 'pending',
            'cv_file_path' => $path,
        ]));

        $url = URL::temporarySignedRoute(
            'admin.volunteer-requests.cv.view',
            now()->addHour(),
            ['volunteerRequest' => $vr->id],
        );

        $this->get($url)->assertOk();
    }

    public function test_unsigned_cv_view_is_rejected(): void
    {
        Storage::fake('public');

        $path = 'volunteer-cvs/cv_unsigned.pdf';
        Storage::disk('public')->put($path, 'content');

        $vr = VolunteerRequest::create(array_merge($this->validPayload(['agree_terms' => null]), [
            'status'       => 'pending',
            'cv_file_path' => $path,
        ]));

        $this->get("/api/admin/volunteer-requests/{$vr->id}/cv/view")
            ->assertForbidden();
    }

    public function test_cv_download_route_no_longer_exists(): void
    {
        $admin = $this->makeAdmin();
        $vr = VolunteerRequest::create(array_merge($this->validPayload(['agree_terms' => null]), [
            'status' => 'pending',
        ]));

        $this->actingAs($admin, 'sanctum')
            ->get("/api/admin/volunteer-requests/{$vr->id}/cv")
            ->assertStatus(404);
    }

    public function test_invalid_status_value_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $vr    = VolunteerRequest::create(array_merge($this->validPayload(['agree_terms' => null]), ['status' => 'pending']));

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/volunteer-requests/{$vr->id}/status", [
                'status' => 'approved',  // not in allowed list
            ])
            ->assertStatus(422);
    }

    // ─── Authorization ────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_access_admin_endpoints(): void
    {
        $this->getJson('/api/admin/volunteer-requests')->assertStatus(401);
    }

    public function test_student_cannot_access_admin_volunteer_requests(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/volunteer-requests')
            ->assertStatus(403);
    }

    // ─── can_convert_to_member flag ───────────────────────────────────────────

    public function test_update_status_to_accepted_returns_can_convert_flag(): void
    {
        $admin = $this->makeAdmin();
        $vr    = VolunteerRequest::create(array_merge($this->validPayload(['agree_terms' => null]), ['status' => 'pending']));

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/volunteer-requests/{$vr->id}/status", ['status' => 'accepted'])
            ->assertOk();

        $this->assertTrue($response->json('can_convert_to_member'));
    }

    public function test_can_convert_flag_is_false_after_conversion(): void
    {
        $admin = $this->makeAdmin();

        // Create a real TeamProfile so the FK is satisfied
        $member = TeamProfile::create([
            'name_ar' => 'عضو تجربة', 'position_ar' => 'متطوع', 'sort_order' => 1,
        ]);

        $vr = VolunteerRequest::create(array_merge($this->validPayload(['agree_terms' => null]), [
            'status'                 => 'accepted',
            'converted_to_member_at' => now(),
            'converted_member_id'    => $member->id,
        ]));

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/volunteer-requests/{$vr->id}/status", ['status' => 'accepted'])
            ->assertOk();

        $this->assertFalse($response->json('can_convert_to_member'));
    }

    // ─── Convert to member ────────────────────────────────────────────────────

    private function makeAcceptedVr(array $overrides = []): VolunteerRequest
    {
        return VolunteerRequest::create(array_merge(
            $this->validPayload(['agree_terms' => null]),
            ['status' => 'accepted'],
            $overrides
        ));
    }

    public function test_accepted_volunteer_can_be_converted_to_member(): void
    {
        $admin = $this->makeAdmin();
        $dept  = Department::create([
            'name' => 'التقنية', 'name_ar' => 'التقنية', 'slug' => 'tech-test',
            'is_active' => true, 'sort_order' => 1,
        ]);
        $vr = $this->makeAcceptedVr(['desired_department' => 'التقنية']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member")
            ->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.member_type', 'volunteer')
            ->assertJsonPath('data.source', 'volunteer_request');

        $memberId = $response->json('data.id');
        $this->assertNotNull($memberId);

        // team_profiles row created
        $this->assertDatabaseHas('team_profiles', [
            'id'          => $memberId,
            'name_ar'     => 'Ahmed Al-Rashidi',
            'department_id' => $dept->id,
            'source'      => 'volunteer_request',
            'source_id'   => $vr->id,
            'is_new_member' => true,
        ]);

        // volunteer_request stamped with conversion metadata
        $fresh = $vr->fresh();
        $this->assertNotNull($fresh->converted_to_member_at);
        $this->assertSame($memberId, $fresh->converted_member_id);
    }

    public function test_non_accepted_volunteer_cannot_be_converted(): void
    {
        $admin = $this->makeAdmin();
        $vr    = VolunteerRequest::create(array_merge($this->validPayload(['agree_terms' => null]), ['status' => 'pending']));

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member")
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_duplicate_conversion_is_blocked(): void
    {
        $admin = $this->makeAdmin();
        $vr    = $this->makeAcceptedVr();

        // First conversion succeeds
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member")
            ->assertCreated();

        // Second conversion blocked with 409
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member")
            ->assertStatus(409)
            ->assertJson(['success' => false]);
    }

    public function test_converted_to_member_at_is_saved(): void
    {
        $admin = $this->makeAdmin();
        $vr    = $this->makeAcceptedVr();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member")
            ->assertCreated();

        $this->assertNotNull($vr->fresh()->converted_to_member_at);
    }

    public function test_converted_member_appears_in_team_profiles(): void
    {
        $admin = $this->makeAdmin();
        $vr    = $this->makeAcceptedVr(['full_name' => 'نور الهدى', 'email' => 'nour@example.com']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member")
            ->assertCreated();

        $this->assertDatabaseHas('team_profiles', ['name_ar' => 'نور الهدى', 'email' => 'nour@example.com']);

        // Confirm TeamProfile model returns it
        $profile = TeamProfile::where('name_ar', 'نور الهدى')->first();
        $this->assertNotNull($profile);
        $this->assertSame('volunteer_request', $profile->source);
        $this->assertTrue($profile->is_new_member);
    }

    public function test_convert_creates_notification_for_admins(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $admin      = $this->makeAdmin();
        $vr         = $this->makeAcceptedVr();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member")
            ->assertCreated();

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $superAdmin->id,
            'type'    => 'member_converted',
        ]);

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $admin->id,
            'type'    => 'member_converted',
        ]);
    }

    public function test_student_cannot_convert_volunteer_to_member(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $vr      = $this->makeAcceptedVr();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member")
            ->assertStatus(403);
    }
}
