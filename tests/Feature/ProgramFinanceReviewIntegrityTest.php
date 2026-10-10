<?php

namespace Tests\Feature;

use App\Exceptions\FinanceApprovalNotPendingException;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Department;
use App\Models\LearningPath;
use App\Models\PlatformNotification;
use App\Models\ProgramFinanceApproval;
use App\Models\User;
use App\Services\ProgramFinanceApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * FINANCE APPROVAL — integrity of the review itself.
 *
 *   1. A pending request gets exactly ONE terminal transition, even when two
 *      reviewers act at once (status re-checked under the row lock).
 *   2. A financial edit and its review request are one atomic unit: the new
 *      values are never readable next to 'approved', and a failed submission
 *      rolls the edit back.
 *   3. Stale approvals, duplicate submissions, value normalization, the
 *      frontend contract and business authorization around the above.
 */
class ProgramFinanceReviewIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ProgramFinanceApprovalService
    {
        return app(ProgramFinanceApprovalService::class);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

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
            'proposed_hash'     => $this->service()->fingerprintHash($this->service()->financeFingerprint($course)),
        ]);

        return $course;
    }

    private function approvedPaidPath(): LearningPath
    {
        $path = LearningPath::create([
            'title'                   => 'مسار ' . uniqid(),
            'slug'                    => 'path-' . uniqid(),
            'price'                   => 200,
            'currency'                => 'EUR',
            'status'                  => 'published',
            'enrollment_open'         => true,
            'finance_approval_status' => 'approved',
        ]);

        ProgramFinanceApproval::create([
            'approvable_type'   => LearningPath::class,
            'approvable_id'     => $path->id,
            'status'            => 'approved',
            'request_type'      => 'create',
            'submitted_at'      => now()->subDay(),
            'reviewed_at'       => now()->subDay(),
            'price_snapshot'    => 200,
            'currency_snapshot' => 'EUR',
            'proposed_hash'     => $this->service()->fingerprintHash($this->service()->financeFingerprint($path)),
        ]);

        return $path;
    }

    /** A course with a pending edit request, returning [course, approvalId]. */
    private function pendingCourseEdit(): array
    {
        $course = $this->approvedPaidCourse();

        $this->actingAs($this->user('super_admin'))
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])
            ->assertOk();

        $approvalId = ProgramFinanceApproval::where('approvable_type', Course::class)
            ->where('approvable_id', $course->id)->value('id');

        return [$course, $approvalId];
    }

    private function auditCount(string $action, int $entityId): int
    {
        return AuditLog::where('action', $action)->where('entity_id', $entityId)->count();
    }

    // ── 1. Exactly one terminal transition ──────────────────────────────────

    public function test_a_second_approve_is_refused_and_has_no_side_effects(): void
    {
        [$course, $approvalId] = $this->pendingCourseEdit();
        $reviewer = $this->user('finance_manager');

        $this->actingAs($reviewer)
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/approve")
            ->assertOk();

        $notifications = PlatformNotification::count();

        $this->actingAs($this->user('finance_manager'))
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/approve")
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'FINANCE_APPROVAL_NOT_PENDING']);

        $this->assertSame($reviewer->id, ProgramFinanceApproval::find($approvalId)->reviewed_by);
        $this->assertSame(1, $this->auditCount('PROGRAM_FINANCE_APPROVED', $course->id));
        $this->assertSame($notifications, PlatformNotification::count());
    }

    public function test_reject_after_approve_is_refused(): void
    {
        [$course, $approvalId] = $this->pendingCourseEdit();

        $this->actingAs($this->user('finance_manager'))
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/approve")
            ->assertOk();

        $this->actingAs($this->user('finance_manager'))
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/reject", ['rejection_reason' => 'لا'])
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'FINANCE_APPROVAL_NOT_PENDING']);

        $this->assertSame('approved', ProgramFinanceApproval::find($approvalId)->status);
        $this->assertSame('approved', $course->fresh()->finance_approval_status);
        $this->assertSame(0, $this->auditCount('PROGRAM_FINANCE_REJECTED', $course->id));
    }

    public function test_approve_after_reject_is_refused(): void
    {
        [$course, $approvalId] = $this->pendingCourseEdit();

        $this->actingAs($this->user('finance_manager'))
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/reject", ['rejection_reason' => 'مرتفع'])
            ->assertOk();

        $this->actingAs($this->user('finance_manager'))
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/approve")
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'FINANCE_APPROVAL_NOT_PENDING']);

        $this->assertSame('rejected', ProgramFinanceApproval::find($approvalId)->status);
        $this->assertSame('rejected', $course->fresh()->finance_approval_status);
        $this->assertSame(0, $this->auditCount('PROGRAM_FINANCE_APPROVED', $course->id));
    }

    /**
     * The race itself: the controller's pre-check saw 'pending', but another
     * reviewer committed before this one took the row lock. SQLite cannot run
     * two real transactions at once, so the losing reviewer is reproduced by
     * calling the service directly on a row that is no longer pending — the
     * exact state the locked re-read observes in production.
     */
    public function test_service_refuses_a_transition_once_the_locked_row_is_terminal(): void
    {
        [$course, $approvalId] = $this->pendingCourseEdit();
        $service = $this->service();

        $service->approve($course->fresh(), $this->user('finance_manager'));
        $notifications = PlatformNotification::count();

        foreach (['approve', 'reject'] as $action) {
            try {
                $action === 'approve'
                    ? $service->approve($course->fresh(), $this->user('finance_manager'))
                    : $service->reject($course->fresh(), $this->user('finance_manager'), 'سبب');
                $this->fail("second {$action} should have been refused");
            } catch (FinanceApprovalNotPendingException) {
                // expected
            }
        }

        $this->assertSame('approved', ProgramFinanceApproval::find($approvalId)->status);
        $this->assertSame(1, $this->auditCount('PROGRAM_FINANCE_APPROVED', $course->id));
        $this->assertSame(0, $this->auditCount('PROGRAM_FINANCE_REJECTED', $course->id));
        $this->assertSame($notifications, PlatformNotification::count());
    }

    // ── 2. Atomic financial edit ────────────────────────────────────────────

    public function test_new_course_price_is_written_together_with_pending(): void
    {
        $course = $this->approvedPaidCourse();

        $observed = [];
        Course::updating(function (Course $c) use (&$observed) {
            if ($c->isDirty('price')) {
                $observed[] = $c->finance_approval_status;
            }
        });

        $this->actingAs($this->user('super_admin'))
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])
            ->assertOk();

        // The one write that changed the price already carried 'pending' —
        // there is no committed state of "new price + approved".
        $this->assertSame(['pending'], $observed);
    }

    public function test_new_path_price_is_written_together_with_pending(): void
    {
        $path = $this->approvedPaidPath();

        $observed = [];
        LearningPath::updating(function (LearningPath $p) use (&$observed) {
            if ($p->isDirty('price')) {
                $observed[] = $p->finance_approval_status;
            }
        });

        $this->actingAs($this->user('super_admin'))
            ->putJson("/api/admin/learning-paths/{$path->id}", ['price' => 250])
            ->assertOk();

        $this->assertSame(['pending'], $observed);
        $this->assertSame('pending', ProgramFinanceApproval::where('approvable_type', LearningPath::class)
            ->where('approvable_id', $path->id)->value('status'));
    }

    public function test_a_failed_submission_rolls_the_course_edit_back(): void
    {
        $course = $this->approvedPaidCourse();

        $this->partialMock(ProgramFinanceApprovalService::class, function ($mock) {
            $mock->shouldReceive('submit')->andThrow(new RuntimeException('finance queue unavailable'));
        });

        $this->actingAs($this->user('super_admin'))
            ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])
            ->assertStatus(500);

        $fresh = $course->fresh();
        $this->assertSame(100.0, (float) $fresh->price);
        $this->assertSame('approved', $fresh->finance_approval_status);
        $this->assertSame('approved', ProgramFinanceApproval::where('approvable_type', Course::class)
            ->where('approvable_id', $course->id)->value('status'));
    }

    public function test_a_failed_submission_rolls_the_path_edit_and_membership_back(): void
    {
        $path   = $this->approvedPaidPath();
        $course = Course::factory()->create();

        $this->partialMock(ProgramFinanceApprovalService::class, function ($mock) {
            $mock->shouldReceive('submit')->andThrow(new RuntimeException('finance queue unavailable'));
        });

        $this->actingAs($this->user('super_admin'))
            ->putJson("/api/admin/learning-paths/{$path->id}", ['price' => 250, 'course_ids' => [$course->id]])
            ->assertStatus(500);

        $fresh = $path->fresh();
        $this->assertSame(200.0, (float) $fresh->price);
        $this->assertSame('approved', $fresh->finance_approval_status);
        $this->assertSame(0, $fresh->courses()->count());
    }

    public function test_a_cosmetic_edit_still_saves_without_opening_a_request(): void
    {
        $course = $this->approvedPaidCourse();

        $this->actingAs($this->user('super_admin'))
            ->putJson("/api/admin/courses/{$course->id}", ['title' => 'عنوان جديد'])
            ->assertOk();

        $this->assertSame('عنوان جديد', $course->fresh()->title);
        $this->assertSame('approved', $course->fresh()->finance_approval_status);
        $this->assertSame(0, $this->auditCount('PROGRAM_SUBMITTED_FOR_FINANCE', $course->id));
    }

    // ── 3. Stale approval ───────────────────────────────────────────────────

    public function test_stale_approval_is_blocked_without_approval_side_effects(): void
    {
        [$course, $approvalId] = $this->pendingCourseEdit();
        $notifications = PlatformNotification::count();

        // Proposal A (150) is pending; the price moves to B behind the reviewer.
        $course->fresh()->update(['price' => 175]);

        $this->actingAs($this->user('finance_manager'))
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/approve")
            ->assertStatus(409)
            ->assertJsonFragment(['code' => 'FINANCE_APPROVAL_STALE']);

        $this->assertSame('pending', ProgramFinanceApproval::find($approvalId)->status);
        $this->assertNull(ProgramFinanceApproval::find($approvalId)->reviewed_by);
        $this->assertSame('pending', $course->fresh()->finance_approval_status);
        $this->assertSame(0, $this->auditCount('PROGRAM_FINANCE_APPROVED', $course->id));
        $this->assertSame($notifications, PlatformNotification::count());

        // The blocked attempt itself is on record (logged after the rollback).
        $this->assertSame(1, $this->auditCount('PROGRAM_FINANCE_STALE_APPROVAL_BLOCKED', $course->id));
    }

    // ── 4. Duplicate submission ─────────────────────────────────────────────

    public function test_resaving_the_same_proposal_keeps_one_request_one_audit_and_its_queue_position(): void
    {
        [$course, $approvalId] = $this->pendingCourseEdit();
        $submittedAt   = ProgramFinanceApproval::find($approvalId)->submitted_at;
        $notifications = PlatformNotification::count();

        $this->travel(5)->minutes();

        foreach ([1, 2] as $_) {
            $this->actingAs($this->user('super_admin'))
                ->putJson("/api/admin/courses/{$course->id}", ['price' => 150])
                ->assertOk();
        }

        $this->assertSame(1, ProgramFinanceApproval::where('approvable_type', Course::class)
            ->where('approvable_id', $course->id)->count());
        $this->assertEquals($submittedAt, ProgramFinanceApproval::find($approvalId)->submitted_at);
        $this->assertSame(1, $this->auditCount('PROGRAM_SUBMITTED_FOR_FINANCE', $course->id));
        $this->assertSame($notifications, PlatformNotification::count());
    }

    // ── 5. Normalization ────────────────────────────────────────────────────

    public function test_equivalent_course_representations_are_not_a_financial_change(): void
    {
        $course = $this->approvedPaidCourse(['pricing_options' => [['label' => 'A', 'price' => 10]]]);

        $this->assertSame([], $this->service()->financeChanges($course, [
            'price'           => '100.00',
            'currency'        => 'eur',
            'is_paid'         => 1,
            'pricing_options' => [['price' => '10.00', 'label' => 'A']],
        ]));

        // Unsaved on purpose: the schema forbids a null price, but a model can
        // still carry one (e.g. before defaults apply) and must fingerprint as 0.
        $free = Course::factory()->make(['price' => null, 'currency' => null, 'pricing_options' => null]);
        $this->assertSame([], $this->service()->financeChanges($free, [
            'price'           => 0,
            'currency'        => 'EUR',
            'pricing_options' => [],
        ]));
    }

    public function test_real_pricing_option_changes_are_still_detected(): void
    {
        $course = $this->approvedPaidCourse(['pricing_options' => [['label' => 'A', 'price' => 10]]]);

        $changes = $this->service()->financeChanges($course, [
            'pricing_options' => [['label' => 'A', 'price' => 12]],
        ]);

        $this->assertArrayHasKey('pricing_options', $changes);
    }

    public function test_equivalent_path_representations_are_not_a_financial_change(): void
    {
        $path = $this->approvedPaidPath();
        $a    = Course::factory()->create();
        $b    = Course::factory()->create();
        $path->courses()->attach([$a->id => ['sort_order' => 1], $b->id => ['sort_order' => 2]]);

        $this->assertSame([], $this->service()->financeChanges(
            $path,
            ['price' => '200', 'discount_price' => 0, 'currency' => 'eur'],
            [$b->id, (string) $a->id, $a->id],
        ));
    }

    // ── 6. Checkout ─────────────────────────────────────────────────────────

    public function test_checkout_refuses_a_rejected_course(): void
    {
        $course = $this->approvedPaidCourse(['finance_approval_status' => 'rejected']);

        $this->actingAs($this->user('student'))
            ->postJson("/api/courses/{$course->id}/checkout")
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'finance_approval_required']);
    }

    public function test_checkout_does_not_finance_block_a_legacy_not_required_paid_course(): void
    {
        // Existing policy, deliberately unchanged: paid courses that predate
        // the finance workflow carry 'not_required' and must keep selling.
        $course = $this->approvedPaidCourse(['finance_approval_status' => 'not_required']);

        $this->actingAs($this->user('student'))
            ->postJson("/api/courses/{$course->id}/checkout")
            ->assertJsonMissing(['code' => 'finance_approval_required']);
    }

    // ── 7. Frontend contract (ca87da2) ──────────────────────────────────────

    public function test_list_and_decision_responses_carry_request_type_and_changes(): void
    {
        [, $approvalId] = $this->pendingCourseEdit();
        $reviewer = $this->user('finance_manager');

        $row = collect($this->actingAs($reviewer)
            ->getJson('/api/admin/finance/program-approvals?status=pending')
            ->assertOk()
            ->json('data'))->firstWhere('id', $approvalId);

        $this->assertSame('update', $row['request_type']);
        $this->assertEquals(['from' => 100, 'to' => 150], $row['changes']['price']);

        $this->actingAs($reviewer)
            ->postJson("/api/admin/finance/program-approvals/{$approvalId}/reject", ['rejection_reason' => 'مرتفع'])
            ->assertOk()
            ->assertJsonPath('data.request_type', 'update')
            ->assertJsonPath('data.status', 'rejected');
    }

    // ── 8. Business authorization ───────────────────────────────────────────

    public function test_non_finance_roles_cannot_approve_or_reject(): void
    {
        [, $approvalId] = $this->pendingCourseEdit();

        $leader = $this->user('programs_manager');
        Department::create([
            'name' => 'Programs ' . Str::random(6), 'name_ar' => 'البرامج', 'slug' => 'programs-' . Str::random(6),
            'status' => 'active', 'is_active' => true, 'leader_id' => $leader->id,
        ]);

        foreach ([$this->user('student'), $this->user('instructor'), $this->user('quality_manager'), $leader] as $user) {
            $this->actingAs($user)
                ->postJson("/api/admin/finance/program-approvals/{$approvalId}/approve")
                ->assertForbidden();
            $this->actingAs($user)
                ->postJson("/api/admin/finance/program-approvals/{$approvalId}/reject", ['rejection_reason' => 'x'])
                ->assertForbidden();
        }

        $this->assertSame('pending', ProgramFinanceApproval::find($approvalId)->status);
    }

    public function test_finance_and_admin_roles_can_review(): void
    {
        foreach (['finance_manager', 'admin', 'super_admin', 'tech_admin'] as $role) {
            [$course, $approvalId] = $this->pendingCourseEdit();

            $this->actingAs($this->user($role))
                ->postJson("/api/admin/finance/program-approvals/{$approvalId}/approve")
                ->assertOk();

            $this->assertSame('approved', $course->fresh()->finance_approval_status, $role);
        }
    }
}
