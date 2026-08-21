<?php

namespace Tests\Feature;

use App\Models\PartnershipRequest;
use App\Models\PartnerProgram;
use App\Models\PartnerReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root cause of the production 422: the frontend sent
 * {institution_name, institution_type, contact_name, email, phone, message}
 * while the backend's inline validation required {partner_name, type (a
 * fixed enum), contact_name, email, phone, message} — two different,
 * never-reconciled schemas. Fixed by aligning the frontend to the backend's
 * existing partner_name/type field names (lower-risk than renaming live
 * columns) and moving validation into StorePartnershipRequestRequest.
 */
class PartnershipRequestTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'partner_name'     => 'EMC Test Partner',
            'type'             => 'academic',
            'contact_name'     => 'Faisal Al-Rashidi',
            'email'            => 'test@test.nl',
            'phone'            => '+31612345678',
            'country'          => 'Netherlands',
            'partnership_type' => 'education_partnership',
            'message'          => 'We would like to explore a partnership.',
            'privacy_accepted' => true,
        ], $overrides);
    }

    // 1: valid request creates a partnership request ---------------------------

    public function test_valid_request_creates_a_partnership_request(): void
    {
        $response = $this->postJson('/api/partnership-requests', $this->validPayload());

        $response->assertCreated()->assertJson(['success' => true]);
        $this->assertDatabaseHas('partnership_requests', [
            'partner_name' => 'EMC Test Partner',
            'type'         => 'academic',
            'contact_name' => 'Faisal Al-Rashidi',
            'email'        => 'test@test.nl',
            'status'       => 'new',
            'privacy_accepted' => true,
        ]);
    }

    // 2: required fields return 422 ---------------------------------------------

    public function test_missing_required_fields_return_422(): void
    {
        $response = $this->postJson('/api/partnership-requests', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'partner_name', 'type', 'contact_name', 'email', 'phone',
                'country', 'partnership_type', 'message', 'privacy_accepted',
            ]);
    }

    /** The literal reproduction payload from the audit — proves the exact production symptom is fixed once the correct keys are sent. */
    public function test_the_original_frontend_payload_shape_now_matches_the_contract(): void
    {
        $response = $this->postJson('/api/partnership-requests', $this->validPayload([
            'partner_name' => 'TEst', 'contact_name' => 'TEst', 'message' => 'test message here',
        ]));

        $response->assertCreated();
    }

    // 3: invalid email returns 422 -----------------------------------------------

    public function test_invalid_email_returns_422(): void
    {
        $response = $this->postJson('/api/partnership-requests', $this->validPayload(['email' => 'not-an-email']));

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    // 4: invalid organization type returns 422 -----------------------------------

    public function test_invalid_organization_type_returns_422(): void
    {
        $response = $this->postJson('/api/partnership-requests', $this->validPayload(['type' => 'academic_but_wrong']));

        $response->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    // 5: invalid partnership type returns 422 -------------------------------------

    public function test_invalid_partnership_type_returns_422(): void
    {
        $response = $this->postJson('/api/partnership-requests', $this->validPayload(['partnership_type' => 'not_a_real_type']));

        $response->assertStatus(422)->assertJsonValidationErrors(['partnership_type']);
    }

    // 6: invalid phone number returns 422 ------------------------------------------

    public function test_invalid_phone_number_returns_422(): void
    {
        $response = $this->postJson('/api/partnership-requests', $this->validPayload(['phone' => 'not-a-phone-number']));

        $response->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    // 7: valid international phone numbers are accepted (after normalization) ----

    public function test_valid_international_phone_numbers_are_accepted_after_normalization(): void
    {
        $samples = ['06 81234567', '+31 6 81234567', '0031 6 81234567', '+970 59 123 4567', '+966 50 123 4567'];

        foreach ($samples as $phone) {
            $response = $this->postJson('/api/partnership-requests', $this->validPayload([
                'phone' => $phone, 'email' => 'phone-test-' . md5($phone) . '@test.nl',
            ]));
            $response->assertCreated("Phone '{$phone}' should have been accepted after normalization.");
        }
    }

    // 8: empty optional strings are stored as null ---------------------------------

    public function test_empty_optional_strings_are_stored_as_null(): void
    {
        $response = $this->postJson('/api/partnership-requests', $this->validPayload([
            'city' => '', 'website' => '',
        ]));

        $response->assertCreated();
        $id = $response->json('data.id');
        $this->assertDatabaseHas('partnership_requests', ['id' => $id, 'city' => null, 'website' => null]);
    }

    public function test_website_without_scheme_is_normalized_to_https(): void
    {
        $response = $this->postJson('/api/partnership-requests', $this->validPayload(['website' => 'example.com']));

        $response->assertCreated();
        $id = $response->json('data.id');
        $this->assertDatabaseHas('partnership_requests', ['id' => $id, 'website' => 'https://example.com']);
    }

    // 9-10: N/A — no preferred_contact_date/time field was added (see Final Report scope decision) —

    // 11: privacy acceptance is required -------------------------------------------

    public function test_privacy_acceptance_is_required(): void
    {
        $response = $this->postJson('/api/partnership-requests', $this->validPayload(['privacy_accepted' => false]));

        $response->assertStatus(422)->assertJsonValidationErrors(['privacy_accepted']);
    }

    // 12: rate limiting returns the intended response --------------------------------

    public function test_rate_limiting_blocks_after_five_requests_per_minute(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/partnership-requests', $this->validPayload([
                'email' => "rate-{$i}@test.nl",
            ]))->assertCreated();
        }

        $this->postJson('/api/partnership-requests', $this->validPayload(['email' => 'rate-6@test.nl']))
            ->assertStatus(429);
    }

    // 13: N/A — no notification/mail dispatch exists for this endpoint (documented gap, not a regression) —

    // 14: public endpoint does not require authentication ----------------------------

    public function test_public_endpoint_does_not_require_authentication(): void
    {
        // No actingAs() call anywhere in this test class — every request above
        // is already unauthenticated. This test asserts it explicitly.
        $response = $this->postJson('/api/partnership-requests', $this->validPayload(['email' => 'unauth@test.nl']));

        $response->assertCreated();
    }

    // Extra: "other" organization/partnership type requires the free-text detail

    public function test_other_organization_type_requires_type_other_detail(): void
    {
        $response = $this->postJson('/api/partnership-requests', $this->validPayload(['type' => 'other']));

        $response->assertStatus(422)->assertJsonValidationErrors(['type_other']);
    }

    public function test_other_organization_type_with_detail_is_accepted(): void
    {
        $response = $this->postJson('/api/partnership-requests', $this->validPayload([
            'type' => 'other', 'type_other' => 'Regional trade association',
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('partnership_requests', ['type' => 'other', 'type_other' => 'Regional trade association']);
    }

    public function test_short_message_is_rejected(): void
    {
        $response = $this->postJson('/api/partnership-requests', $this->validPayload(['message' => 'hi']));

        $response->assertStatus(422)->assertJsonValidationErrors(['message']);
    }

    public function test_admin_approval_creates_partner_account_and_portal_membership(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $application = PartnershipRequest::create($this->validPayload([
            'email' => 'approved-partner@example.com',
        ]));

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/operations/partnership-requests/{$application->id}", [
                'status' => 'approved',
                'review_notes' => 'طلب متوافق مع أهداف المركز.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('onboarding.account_created', true)
            ->assertJsonPath('onboarding.credential_setup', 'password_reset');

        $partnerId = (int) $response->json('onboarding.partner_id');
        $userId = (int) $response->json('onboarding.user_id');

        $this->assertDatabaseHas('partners', [
            'id' => $partnerId,
            'source_request_id' => $application->id,
            'name' => $application->partner_name,
            'status' => 'active',
            'onboarding_status' => 'account_ready',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'approved-partner@example.com',
            'role' => 'partner',
            'must_change_password' => 1,
        ]);
        $this->assertDatabaseHas('partner_users', [
            'partner_id' => $partnerId,
            'user_id' => $userId,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('partnership_requests', [
            'id' => $application->id,
            'converted_partner_id' => $partnerId,
            'converted_user_id' => $userId,
            'reviewed_by' => $admin->id,
        ]);
    }

    public function test_approval_is_idempotent_and_does_not_duplicate_partner_or_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $application = PartnershipRequest::create($this->validPayload([
            'email' => 'idempotent-partner@example.com',
        ]));

        $endpoint = "/api/operations/partnership-requests/{$application->id}";
        $this->actingAs($admin, 'sanctum')->patchJson($endpoint, ['status' => 'approved'])->assertOk();
        $this->actingAs($admin, 'sanctum')->patchJson($endpoint, ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('onboarding.account_created', false);

        $this->assertDatabaseCount('partners', 1);
        $this->assertSame(1, User::where('email', 'idempotent-partner@example.com')->count());
        $this->assertDatabaseCount('partner_users', 1);
    }

    public function test_approval_stops_when_email_belongs_to_an_incompatible_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        User::factory()->create(['role' => 'student', 'email' => 'existing-student@example.com']);
        $application = PartnershipRequest::create($this->validPayload([
            'email' => 'existing-student@example.com',
        ]));

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/operations/partnership-requests/{$application->id}", ['status' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'partner_onboarding_conflict');

        $this->assertDatabaseCount('partners', 0);
        $this->assertDatabaseCount('partner_users', 0);
        $this->assertDatabaseHas('partnership_requests', [
            'id' => $application->id,
            'status' => 'new',
        ]);
    }

    public function test_partner_dashboard_returns_real_program_and_report_metrics(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $application = PartnershipRequest::create($this->validPayload([
            'email' => 'portal-partner@example.com',
        ]));
        $approval = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/operations/partnership-requests/{$application->id}", ['status' => 'approved'])
            ->assertOk();

        $partnerId = (int) $approval->json('onboarding.partner_id');
        $partnerUser = User::findOrFail((int) $approval->json('onboarding.user_id'));
        PartnerProgram::create([
            'partner_id' => $partnerId,
            'title' => 'برنامج قيادي مشترك',
            'status' => 'active',
            'participant_count' => 42,
        ]);
        PartnerReport::create([
            'partner_id' => $partnerId,
            'created_by' => $admin->id,
            'title' => 'تقرير الربع الأول',
            'status' => 'published',
        ]);

        $this->actingAs($partnerUser, 'sanctum')
            ->getJson('/api/partner/dashboard')
            ->assertOk()
            ->assertJsonPath('data.partner.name', $application->partner_name)
            ->assertJsonPath('data.joint_programs_count', 1)
            ->assertJsonPath('data.active_programs', 1)
            ->assertJsonPath('data.participants_total', 42)
            ->assertJsonPath('data.reports_count', 1)
            ->assertJsonPath('data.impact_score', null)
            ->assertJsonPath('data.recent_reports.0.title', 'تقرير الربع الأول');
    }

    public function test_partner_crm_preserves_operational_fields_and_returns_real_kpis(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/operations/partners', [
                'name' => 'جهة تقنية تجريبية',
                'type' => 'company',
                'project_scope' => 'HACKATHON',
                'classification' => 'Under Negotiation',
                'field_of_work' => 'التقنية التعليمية',
                'contact_person' => 'ليان خالد',
                'contact_position' => 'مديرة الشراكات',
                'email' => 'crm-partner@example.com',
                'assigned_to' => 'فريق العلاقات',
                'first_contact_date' => '2026-08-01',
                'last_contact_date' => '2026-08-20',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.project_scope', 'HACKATHON')
            ->assertJsonPath('data.classification', 'Under Negotiation')
            ->assertJsonPath('data.contact_position', 'مديرة الشراكات');

        $partnerId = (int) $created->json('data.id');
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/operations/partners?project_scope=HACKATHON')
            ->assertOk()
            ->assertJsonPath('kpis.total', 1)
            ->assertJsonPath('kpis.negotiation', 1)
            ->assertJsonPath('data.data.0.id', $partnerId);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/operations/partners/{$partnerId}", [
                'classification' => 'Actual Partner',
                'project_scope' => 'HACKATHON',
            ])
            ->assertOk()
            ->assertJsonPath('data.classification', 'Actual Partner');
    }
}
