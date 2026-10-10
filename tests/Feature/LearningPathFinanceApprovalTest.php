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
 * FINANCE APPROVAL — Learning Paths.
 *
 * A Learning Path carries its OWN price column (it is not derived from its
 * courses), and LearningPathCheckoutController charges discount_price when one
 * is set. Both therefore count as financial. Course membership counts too: the
 * buyer pays the path's own price for a specific bundle, so swapping courses
 * in or out changes what is being sold even when the number does not move.
 */
class LearningPathFinanceApprovalTest extends TestCase
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

    private function approvedPaidPath(array $overrides = []): LearningPath
    {
        $path = LearningPath::create(array_merge([
            'title'                   => 'مسار تجريبي ' . uniqid(),
            'slug'                    => 'path-' . uniqid(),
            'price'                   => 200,
            'currency'                => 'EUR',
            'status'                  => 'published',
            'enrollment_open'         => true,
            'finance_approval_status' => 'approved',
        ], $overrides));

        $service = app(ProgramFinanceApprovalService::class);
        ProgramFinanceApproval::create([
            'approvable_type'   => LearningPath::class,
            'approvable_id'     => $path->id,
            'status'            => 'approved',
            'request_type'      => 'create',
            'submitted_at'      => now()->subDay(),
            'reviewed_at'       => now()->subDay(),
            'price_snapshot'    => 200,
            'currency_snapshot' => 'EUR',
            'proposed_hash'     => $service->fingerprintHash($service->financeFingerprint($path)),
        ]);

        return $path;
    }

    // ── Creation ────────────────────────────────────────────────────────────

    public function test_creating_a_paid_learning_path_requires_finance_and_creates_a_request(): void
    {
        $manager = $this->financeManager();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/learning-paths', [
                'title'  => 'مسار مدفوع جديد',
                'price'  => 300,
                'status' => 'draft',
            ])->assertCreated();

        $path = LearningPath::where('title', 'مسار مدفوع جديد')->first();

        $this->assertSame('pending', $path->finance_approval_status);
        $this->assertDatabaseHas('program_finance_approvals', [
            'approvable_type' => LearningPath::class,
            'approvable_id'   => $path->id,
            'status'          => 'pending',
            'request_type'    => 'create',
        ]);
        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $manager->id,
            'type'    => 'finance_approval_request',
        ]);
    }

    public function test_a_free_learning_path_needs_no_finance_review(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/learning-paths', [
                'title'  => 'مسار مجاني',
                'price'  => 0,
                'status' => 'draft',
            ])->assertCreated();

        $path = LearningPath::where('title', 'مسار مجاني')->first();

        $this->assertSame('not_required', $path->finance_approval_status);
        $this->assertDatabaseMissing('program_finance_approvals', [
            'approvable_type' => LearningPath::class,
            'approvable_id'   => $path->id,
        ]);
    }

    // ── Edits ───────────────────────────────────────────────────────────────

    public function test_price_edit_on_an_approved_path_creates_a_pending_update_request(): void
    {
        $path = $this->approvedPaidPath();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/learning-paths/{$path->id}", ['price' => 250])
            ->assertOk();

        $approval = ProgramFinanceApproval::where('approvable_type', LearningPath::class)
            ->where('approvable_id', $path->id)->first();

        $this->assertSame('pending', $approval->status);
        $this->assertSame('update', $approval->request_type);
        $this->assertEquals(200, $approval->changes_snapshot['price']['from']);
        $this->assertEquals(250, $approval->changes_snapshot['price']['to']);
    }

    public function test_discount_price_edit_requires_reapproval_because_it_is_what_gets_charged(): void
    {
        $path = $this->approvedPaidPath();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/learning-paths/{$path->id}", ['discount_price' => 120])
            ->assertOk();

        $this->assertSame('pending', $path->fresh()->finance_approval_status);
        $this->assertArrayHasKey(
            'discount_price',
            ProgramFinanceApproval::where('approvable_id', $path->id)
                ->where('approvable_type', LearningPath::class)->first()->changes_snapshot
        );
    }

    public function test_a_cosmetic_path_edit_does_not_disturb_the_approval(): void
    {
        $path = $this->approvedPaidPath();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/learning-paths/{$path->id}", ['short_description' => 'وصف جديد'])
            ->assertOk();

        $this->assertSame('approved', $path->fresh()->finance_approval_status);
    }

    // ── Course membership (the bundle's contents) ───────────────────────────

    public function test_adding_a_course_to_an_approved_paid_path_requires_reapproval(): void
    {
        $path    = $this->approvedPaidPath();
        $courseA = Course::factory()->create();
        $path->courses()->attach($courseA->id, ['sort_order' => 1]);

        // Re-baseline the approval against the path as it stands WITH course A.
        $service = app(ProgramFinanceApprovalService::class);
        ProgramFinanceApproval::where('approvable_id', $path->id)
            ->where('approvable_type', LearningPath::class)
            ->update(['proposed_hash' => $service->fingerprintHash($service->financeFingerprint($path->fresh()))]);

        $courseB = Course::factory()->create();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/learning-paths/{$path->id}", [
                'course_ids' => [$courseA->id, $courseB->id],
            ])->assertOk();

        $approval = ProgramFinanceApproval::where('approvable_id', $path->id)
            ->where('approvable_type', LearningPath::class)->first();

        $this->assertSame('pending', $approval->status);
        $this->assertArrayHasKey('course_ids', $approval->changes_snapshot);
        $this->assertContains($courseB->id, $approval->changes_snapshot['course_ids']['to']);
    }

    public function test_removing_a_course_from_an_approved_paid_path_requires_reapproval(): void
    {
        $path    = $this->approvedPaidPath();
        $courseA = Course::factory()->create();
        $courseB = Course::factory()->create();
        $path->courses()->attach([$courseA->id => ['sort_order' => 1], $courseB->id => ['sort_order' => 2]]);

        $service = app(ProgramFinanceApprovalService::class);
        ProgramFinanceApproval::where('approvable_id', $path->id)
            ->where('approvable_type', LearningPath::class)
            ->update(['proposed_hash' => $service->fingerprintHash($service->financeFingerprint($path->fresh()))]);

        $this->actingAs($this->admin())
            ->putJson("/api/admin/learning-paths/{$path->id}", ['course_ids' => [$courseA->id]])
            ->assertOk();

        $approval = ProgramFinanceApproval::where('approvable_id', $path->id)
            ->where('approvable_type', LearningPath::class)->first();

        $this->assertSame('pending', $approval->status);
        $this->assertNotContains($courseB->id, $approval->changes_snapshot['course_ids']['to']);
    }

    public function test_membership_change_on_a_free_path_needs_no_finance_review(): void
    {
        $path = LearningPath::create([
            'title' => 'مسار مجاني ' . uniqid(), 'slug' => 'free-' . uniqid(),
            'price' => 0, 'status' => 'published', 'finance_approval_status' => 'not_required',
        ]);
        $course = Course::factory()->create();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/learning-paths/{$path->id}", ['course_ids' => [$course->id]])
            ->assertOk();

        $this->assertSame('not_required', $path->fresh()->finance_approval_status);
    }

    // ── Commercial safety ───────────────────────────────────────────────────

    public function test_a_pending_path_edit_hides_it_from_the_public_catalog(): void
    {
        $path = $this->approvedPaidPath();

        $ids = collect($this->getJson('/api/learning-paths')->json('data') ?? [])->pluck('id');
        $this->assertTrue($ids->contains($path->id));

        $this->actingAs($this->admin())
            ->putJson("/api/admin/learning-paths/{$path->id}", ['price' => 250])->assertOk();

        $ids = collect($this->getJson('/api/learning-paths')->json('data') ?? [])->pluck('id');
        $this->assertFalse($ids->contains($path->id));
    }

    public function test_a_financial_path_edit_cannot_publish_in_the_same_request(): void
    {
        $path = $this->approvedPaidPath(['status' => 'draft']);

        $this->actingAs($this->admin())
            ->putJson("/api/admin/learning-paths/{$path->id}", ['price' => 250, 'status' => 'published'])
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'FINANCE_APPROVAL_REQUIRED']);
    }

    // ── Review outcomes ─────────────────────────────────────────────────────

    public function test_approving_a_path_edit_restores_public_visibility(): void
    {
        $path = $this->approvedPaidPath();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/learning-paths/{$path->id}", ['price' => 250])->assertOk();
        $approvalId = ProgramFinanceApproval::where('approvable_id', $path->id)
            ->where('approvable_type', LearningPath::class)->first()->id;

        $this->actingAs($this->financeManager())
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/approve")
            ->assertOk();

        $this->assertSame('approved', $path->fresh()->finance_approval_status);
        $ids = collect($this->getJson('/api/learning-paths')->json('data') ?? [])->pluck('id');
        $this->assertTrue($ids->contains($path->id));
    }

    public function test_rejecting_a_path_edit_keeps_it_out_of_the_catalog(): void
    {
        $path = $this->approvedPaidPath();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/learning-paths/{$path->id}", ['price' => 250])->assertOk();
        $approvalId = ProgramFinanceApproval::where('approvable_id', $path->id)
            ->where('approvable_type', LearningPath::class)->first()->id;

        $this->actingAs($this->financeManager())
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/reject", [
                'rejection_reason' => 'السعر مرتفع',
            ])->assertOk();

        $this->assertSame('rejected', $path->fresh()->finance_approval_status);
        $ids = collect($this->getJson('/api/learning-paths')->json('data') ?? [])->pluck('id');
        $this->assertFalse($ids->contains($path->id));
    }

    public function test_duplicate_path_saves_do_not_create_duplicate_requests(): void
    {
        $manager = $this->financeManager();
        $path    = $this->approvedPaidPath();

        foreach ([1, 2, 3] as $_) {
            $this->actingAs($this->admin())
                ->putJson("/api/admin/learning-paths/{$path->id}", ['price' => 250])->assertOk();
        }

        $this->assertSame(1, ProgramFinanceApproval::where('approvable_id', $path->id)
            ->where('approvable_type', LearningPath::class)->count());
        $this->assertSame(1, PlatformNotification::where('user_id', $manager->id)
            ->where('type', 'finance_approval_request')->count());
    }

    public function test_an_unauthorized_user_cannot_approve_a_path_request(): void
    {
        $path = $this->approvedPaidPath();
        $this->actingAs($this->admin())
            ->putJson("/api/admin/learning-paths/{$path->id}", ['price' => 250])->assertOk();
        $approvalId = ProgramFinanceApproval::where('approvable_id', $path->id)
            ->where('approvable_type', LearningPath::class)->first()->id;

        $this->actingAs(User::factory()->create(['role' => 'student', 'is_active' => true]))
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/approve")
            ->assertForbidden();
    }

    public function test_the_finance_list_can_be_filtered_to_learning_paths_only(): void
    {
        $path = $this->approvedPaidPath();
        $this->actingAs($this->admin())
            ->putJson("/api/admin/learning-paths/{$path->id}", ['price' => 250])->assertOk();

        $course = Course::factory()->create([
            'type' => 'paid', 'is_paid' => true, 'price' => 50,
            'finance_approval_status' => 'pending',
        ]);
        ProgramFinanceApproval::create([
            'approvable_type' => Course::class, 'approvable_id' => $course->id,
            'status' => 'pending', 'request_type' => 'create', 'submitted_at' => now(),
        ]);

        $response = $this->actingAs($this->financeManager())
            ->getJson('/api/admin/finance/program-approvals?status=pending&approvable_type=LearningPath')
            ->assertOk();

        $types = collect($response->json('data'))->pluck('approvable_type')->unique();
        $this->assertSame(['LearningPath'], $types->values()->all());
    }
}
