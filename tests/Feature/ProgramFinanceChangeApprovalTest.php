<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\LearningPath;
use App\Models\PlatformNotification;
use App\Models\ProgramFinanceApproval;
use App\Models\User;
use App\Services\ProgramFinanceApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FINANCE APPROVAL — changes to already-approved programs.
 *
 * Root cause this covers: AdminCourseController::update() used to flip the
 * denormalized courses.finance_approval_status column to 'pending' by hand and
 * stop there. The program_finance_approvals row — the only thing the Finance
 * approvals screen and its notifications read — was never touched, so an
 * edited paid course silently dropped out of the public catalog while never
 * appearing in any reviewer's queue. Both program types now route every
 * financially material edit through ProgramFinanceApprovalService.
 */
class ProgramFinanceChangeApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function financeManager(): User
    {
        return User::factory()->create(['role' => 'finance_manager', 'is_active' => true]);
    }

    /** An approved, live, paid course — the starting point for every edit case. */
    private function approvedPaidCourse(array $overrides = []): Course
    {
        $course = Course::factory()->create(array_merge([
            'type'                    => 'paid',
            'is_paid'                 => true,
            'price'                   => 100,
            'currency'                => 'EUR',
            'status'                  => 'published',
            'finance_approval_status' => 'approved',
        ], $overrides));

        ProgramFinanceApproval::create([
            'approvable_type'   => Course::class,
            'approvable_id'     => $course->id,
            'status'            => 'approved',
            'request_type'      => 'create',
            'submitted_at'      => now()->subDay(),
            'reviewed_at'       => now()->subDay(),
            'price_snapshot'    => 100,
            'currency_snapshot' => 'EUR',
            'proposed_hash'     => app(ProgramFinanceApprovalService::class)
                ->fingerprintHash(app(ProgramFinanceApprovalService::class)->financeFingerprint($course)),
        ]);

        return $course;
    }

    // ── The reported bug ────────────────────────────────────────────────────

    public function test_price_edit_on_an_approved_course_creates_a_pending_finance_request(): void
    {
        $course = $this->approvedPaidCourse();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])
            ->assertOk();

        $approval = ProgramFinanceApproval::where('approvable_id', $course->id)
            ->where('approvable_type', Course::class)
            ->first();

        $this->assertSame('pending', $approval->status);
        $this->assertSame('update', $approval->request_type);
        $this->assertSame('pending', $course->fresh()->finance_approval_status);
    }

    public function test_the_finance_list_endpoint_actually_returns_the_edit_request(): void
    {
        // A DB row alone is not the fix — the reviewer has to be able to find it.
        $course = $this->approvedPaidCourse();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])
            ->assertOk();

        $response = $this->actingAs($this->financeManager())
            ->getJson('/api/admin/finance/program-approvals?status=pending')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('approvable_id');
        $this->assertTrue($ids->contains($course->id));
        $this->assertSame('update', $response->json('data.0.request_type'));
    }

    public function test_the_edit_request_carries_the_before_and_after_diff(): void
    {
        $course = $this->approvedPaidCourse();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])
            ->assertOk();

        $changes = ProgramFinanceApproval::where('approvable_id', $course->id)->first()->changes_snapshot;

        // assertEquals, not assertSame: the diff round-trips through JSON, so
        // a whole-number float comes back as an int.
        $this->assertEquals(100, $changes['price']['from']);
        $this->assertEquals(150, $changes['price']['to']);
    }

    public function test_finance_managers_are_notified_about_the_edit(): void
    {
        $manager = $this->financeManager();
        $course  = $this->approvedPaidCourse();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])
            ->assertOk();

        $notification = PlatformNotification::where('user_id', $manager->id)
            ->where('type', 'finance_approval_request')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('/dashboard/finance/program-approvals', $notification->action_url);
        // The reviewer must be able to tell an edit from a brand-new program.
        $this->assertStringContainsString('تعديل', $notification->title);
    }

    // ── Which edits count ───────────────────────────────────────────────────

    public function test_a_cosmetic_edit_does_not_disturb_an_approved_course(): void
    {
        $course = $this->approvedPaidCourse();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['short_description' => 'وصف محدَّث'])
            ->assertOk();

        $this->assertSame('approved', $course->fresh()->finance_approval_status);
        $this->assertSame('approved', ProgramFinanceApproval::where('approvable_id', $course->id)->first()->status);
    }

    /**
     * NOTE: `currency` is NOT in AdminCourseController's validation rules, so
     * it cannot be edited through the admin course endpoint at all today —
     * asserting via HTTP would assert a capability the product does not have.
     * The rule is still enforced where it matters: currency is part of the
     * canonical fingerprint, so a currency change made by any other path
     * (console command, direct model write, a future endpoint) is detected as
     * financially material and invalidates an existing approval's hash.
     */
    public function test_currency_change_counts_as_a_financial_change(): void
    {
        $course  = $this->approvedPaidCourse();
        $service = app(ProgramFinanceApprovalService::class);

        $changes = $service->financeChanges($course, ['currency' => 'USD']);

        $this->assertArrayHasKey('currency', $changes);
        $this->assertSame('EUR', $changes['currency']['from']);
        $this->assertSame('USD', $changes['currency']['to']);
    }

    public function test_a_currency_change_makes_an_existing_approval_stale(): void
    {
        $course = $this->approvedPaidCourse();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])->assertOk();
        $approvalId = ProgramFinanceApproval::where('approvable_id', $course->id)->first()->id;

        $course->fresh()->update(['currency' => 'USD']);

        $this->actingAs($this->financeManager())
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/approve")
            ->assertStatus(409);
    }

    public function test_price_decrease_also_requires_reapproval(): void
    {
        $course = $this->approvedPaidCourse(['price' => 150]);

        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 100])
            ->assertOk();

        $this->assertSame('pending', $course->fresh()->finance_approval_status);
    }

    public function test_free_to_paid_requires_approval_and_creates_a_request(): void
    {
        $course = Course::factory()->create([
            'type' => 'free', 'price' => 0, 'is_paid' => false,
            'status' => 'published', 'finance_approval_status' => 'not_required',
        ]);

        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['type' => 'paid', 'price' => 80])
            ->assertOk();

        $this->assertSame('pending', $course->fresh()->finance_approval_status);
        $this->assertDatabaseHas('program_finance_approvals', [
            'approvable_type' => Course::class,
            'approvable_id'   => $course->id,
            'status'          => 'pending',
        ]);
    }

    public function test_paid_to_free_follows_the_existing_no_approval_policy(): void
    {
        // Documented existing EMC behaviour, deliberately preserved here
        // rather than silently changed — see the report's product decisions.
        $course = $this->approvedPaidCourse();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['type' => 'free', 'price' => 0])
            ->assertOk();

        $this->assertSame('not_required', $course->fresh()->finance_approval_status);
    }

    // ── Safety of the live/commercial state ─────────────────────────────────

    public function test_a_pending_price_edit_removes_the_course_from_the_public_catalog(): void
    {
        $course = $this->approvedPaidCourse();

        $this->getJson('/api/courses')->assertOk()
            ->assertJsonFragment(['id' => $course->id]);

        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])
            ->assertOk();

        $ids = collect($this->getJson('/api/courses')->json('data') ?? [])->pluck('id');
        $this->assertFalse($ids->contains($course->id));
    }

    public function test_checkout_refuses_a_price_that_finance_has_not_approved(): void
    {
        $course  = $this->approvedPaidCourse();
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])
            ->assertOk();

        $this->actingAs($student)
            ->postJson("/api/courses/{$course->id}/checkout")
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'finance_approval_required']);
    }

    public function test_a_financial_edit_cannot_publish_in_the_same_request(): void
    {
        $course = $this->approvedPaidCourse(['status' => 'draft']);

        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150, 'status' => 'published'])
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'FINANCE_APPROVAL_REQUIRED']);
    }

    // ── Duplicate + stale protection ────────────────────────────────────────

    public function test_saving_the_same_change_twice_does_not_create_a_second_request(): void
    {
        $manager = $this->financeManager();
        $course  = $this->approvedPaidCourse();

        foreach ([1, 2, 3] as $_) {
            $this->actingAs($this->admin())
                ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])
                ->assertOk();
        }

        $this->assertSame(1, ProgramFinanceApproval::where('approvable_id', $course->id)
            ->where('approvable_type', Course::class)->count());

        // And Finance is not spammed once per save.
        $this->assertSame(1, PlatformNotification::where('user_id', $manager->id)
            ->where('type', 'finance_approval_request')->count());
    }

    public function test_finance_cannot_approve_a_version_that_was_edited_again(): void
    {
        $course = $this->approvedPaidCourse();

        // V1 goes to Finance…
        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])
            ->assertOk();
        $approvalId = ProgramFinanceApproval::where('approvable_id', $course->id)->first()->id;

        // …then the price moves again behind the reviewer's back, without
        // going through the controller (a direct write, a job, a console fix).
        $course->fresh()->update(['price' => 999]);

        $this->actingAs($this->financeManager())
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/approve")
            ->assertStatus(409)
            ->assertJsonFragment(['code' => 'FINANCE_APPROVAL_STALE']);

        $this->assertSame('pending', ProgramFinanceApproval::find($approvalId)->status);
    }

    // ── Approve / reject outcomes ───────────────────────────────────────────

    public function test_approving_an_edit_makes_the_new_price_effective_again(): void
    {
        $course = $this->approvedPaidCourse();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])
            ->assertOk();
        $approvalId = ProgramFinanceApproval::where('approvable_id', $course->id)->first()->id;

        $this->actingAs($this->financeManager())
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/approve")
            ->assertOk();

        $this->assertSame('approved', $course->fresh()->finance_approval_status);
        $this->assertSame(150.0, (float) $course->fresh()->price);

        $ids = collect($this->getJson('/api/courses')->json('data') ?? [])->pluck('id');
        $this->assertTrue($ids->contains($course->id));
    }

    public function test_rejecting_an_edit_leaves_the_course_rejected_and_not_publicly_sellable(): void
    {
        $course = $this->approvedPaidCourse();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])
            ->assertOk();
        $approvalId = ProgramFinanceApproval::where('approvable_id', $course->id)->first()->id;

        $this->actingAs($this->financeManager())
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/reject", [
                'rejection_reason' => 'الزيادة غير مبررة',
            ])->assertOk();

        $this->assertSame('rejected', $course->fresh()->finance_approval_status);
        $this->assertSame('الزيادة غير مبررة', ProgramFinanceApproval::find($approvalId)->rejection_reason);

        // A rejected edit must never be sellable at the unapproved price.
        $ids = collect($this->getJson('/api/courses')->json('data') ?? [])->pluck('id');
        $this->assertFalse($ids->contains($course->id));
    }

    // ── Authorization (business authorization, not page access) ─────────────

    public function test_a_student_cannot_approve_a_finance_request(): void
    {
        $course = $this->approvedPaidCourse();
        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])->assertOk();
        $approvalId = ProgramFinanceApproval::where('approvable_id', $course->id)->first()->id;

        $this->actingAs(User::factory()->create(['role' => 'student', 'is_active' => true]))
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/approve")
            ->assertForbidden();

        $this->assertSame('pending', ProgramFinanceApproval::find($approvalId)->status);
    }

    public function test_an_instructor_cannot_approve_a_finance_request(): void
    {
        $course = $this->approvedPaidCourse();
        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])->assertOk();
        $approvalId = ProgramFinanceApproval::where('approvable_id', $course->id)->first()->id;

        $this->actingAs(User::factory()->create(['role' => 'instructor', 'is_active' => true]))
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/approve")
            ->assertForbidden();
    }

    // ── Existing students ───────────────────────────────────────────────────

    public function test_a_pending_financial_edit_does_not_revoke_an_existing_enrollment(): void
    {
        $course  = $this->approvedPaidCourse();
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);

        \App\Models\Registration::create([
            'user_id'   => $student->id,
            'course_id' => $course->id,
            'status'    => 'confirmed',
            'full_name' => $student->name,
            'email'     => $student->email,
        ]);

        $this->actingAs($this->admin())
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])->assertOk();

        $this->assertDatabaseHas('registrations', [
            'user_id'   => $student->id,
            'course_id' => $course->id,
            'status'    => 'confirmed',
        ]);
    }
}
