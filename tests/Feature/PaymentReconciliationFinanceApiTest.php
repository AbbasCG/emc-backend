<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Instructor;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReconciliation;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesStripe;
use Tests\TestCase;

/**
 * The finance side of payment reconciliation: what a case shows, who may see and settle it, how a refund follow-up is tracked
 * (as a to-do marker only: no payment is ever marked refunded), and what the payer sees meanwhile.
 */
class PaymentReconciliationFinanceApiTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
        $this->fakeStripe();
    }

    protected function tearDown(): void
    {
        $this->restoreStripe();
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function everyRole(): array
    {
        $cases = [];
        foreach (array_unique([...array_column(RoleSeeder::ROLES, 'name'), 'teacher']) as $role) {
            $cases[$role] = [$role];
        }

        return $cases;
    }

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /** @return array{0: User, 1: Course, 2: Registration, 3: Payment, 4: PaymentReconciliation} a withheld enrollment */
    private function openCase(string $payerRole = 'volunteer'): array
    {
        $payer = $this->user($payerRole);
        $course = Course::factory()->paid(60)->create(['registration_open' => true, 'status' => 'published', 'title' => 'دورة التحليل']);
        $this->actingAs($payer, 'sanctum')->postJson("/api/courses/{$course->id}/checkout", [])->assertOk();
        $order = Order::where('user_id', $payer->id)->firstOrFail();
        $registration = Registration::where('user_id', $payer->id)->firstOrFail();
        $payment = Payment::where('order_id', $order->id)->firstOrFail();

        $profile = Instructor::create(['user_id' => $payer->id, 'name' => $payer->name]);
        $course->update(['instructor_id' => $profile->id]);
        $this->stripeWebhook('checkout.session.completed', $payment->provider_payment_id, [
            'order_id' => (string) $order->id, 'registration_id' => (string) $registration->id, 'user_id' => (string) $payer->id, 'course_id' => (string) $course->id,
        ], $this->paidObject())->assertOk();

        return [$payer, $course, $registration, $payment, PaymentReconciliation::where('payment_id', $payment->id)->firstOrFail()];
    }

    // ── authorization ────────────────────────────────────────────────────────

    #[DataProvider('everyRole')]
    public function test_only_finance_and_the_admin_tier_see_or_settle_reconciliation_cases(string $role): void
    {
        [, , , , $case] = $this->openCase();
        $user = $this->user($role);
        $allowed = in_array($role, ['finance_manager', 'admin', 'super_admin', 'tech_admin'], true);

        $this->actingAs($user, 'sanctum')->getJson('/api/finance/payment-reconciliations')->assertStatus($allowed ? 200 : 403);
        $resolve = $this->actingAs($user, 'sanctum')->postJson("/api/finance/payment-reconciliations/{$case->id}/resolve", ['resolution' => 'closed_without_enrollment', 'note' => 'ملاحظة داخلية كافية']);
        $this->assertSame($allowed, $resolve->status() === 200, "[{$role}] resolve");
        if (! $allowed) {
            $this->assertSame('open', $case->fresh()->status);
        }
    }

    public function test_a_guest_cannot_reach_it(): void
    {
        $this->getJson('/api/finance/payment-reconciliations')->assertUnauthorized();
    }

    // ── what a case shows ────────────────────────────────────────────────────

    public function test_a_case_carries_everything_finance_needs_to_decide(): void
    {
        [$payer, $course, $registration, $payment, $case] = $this->openCase();
        $finance = $this->user('finance_manager');

        $body = $this->actingAs($finance, 'sanctum')->getJson('/api/finance/payment-reconciliations')->assertOk()->json();
        $row = $body['data'][0];

        $this->assertSame($case->id, $row['id']);
        $this->assertSame('open', $row['status']);
        $this->assertSame('instructor_conflict', $row['reason']);
        $this->assertSame($payer->id, $row['user']['id']);
        $this->assertSame($payer->email, $row['user']['email']);
        $this->assertSame(['type' => 'course', 'id' => $course->id, 'title' => 'دورة التحليل', 'slug' => $course->slug], $row['program']);
        $this->assertEquals(60, $row['payment']['amount']);
        $this->assertSame('EUR', $row['payment']['currency']);
        $this->assertSame('payment_confirmed', $row['payment']['status']);
        $this->assertSame($payment->provider_payment_id, $row['payment']['provider_reference']);
        $this->assertNotNull($row['order']['number']);
        $this->assertSame(Invoice::where('order_id', $row['order']['id'])->value('invoice_number'), $row['invoice']['number']);
        $this->assertSame('pending_payment', $row['registration']['status']);
        $this->assertSame(['enrollment_activated', 'closed_without_enrollment'], $row['actions']);
        $this->assertTrue($row['conflict_still_present'], 'the payer still teaches it: activation would be refused');
        $this->assertNotNull($row['created_at']);
        $this->assertNull($row['refund_status']);
        $this->assertSame(['open' => 1, 'resolved' => 0, 'refund_pending' => 0], $body['summary']);
    }

    public function test_a_workshop_is_labelled_as_a_workshop(): void
    {
        $payer = $this->user('partner');
        $workshop = Course::factory()->paid(30)->create(['registration_open' => true, 'status' => 'published', 'program_type' => 'workshop']);
        $this->actingAs($payer, 'sanctum')->postJson("/api/courses/{$workshop->id}/checkout", [])->assertOk();
        $order = Order::where('user_id', $payer->id)->firstOrFail();
        $registration = Registration::where('user_id', $payer->id)->firstOrFail();
        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $workshop->update(['instructor_id' => Instructor::create(['user_id' => $payer->id, 'name' => $payer->name])->id]);
        $this->stripeWebhook('checkout.session.completed', $payment->provider_payment_id, ['order_id' => (string) $order->id, 'registration_id' => (string) $registration->id, 'user_id' => (string) $payer->id, 'course_id' => (string) $workshop->id], $this->paidObject())->assertOk();

        $row = $this->actingAs($this->user('finance_manager'), 'sanctum')->getJson('/api/finance/payment-reconciliations')->json('data.0');

        $this->assertSame('workshop', $row['program']['type']);
    }

    public function test_filters_and_search_narrow_the_list_but_the_summary_stays_true(): void
    {
        [$payerA, , , , $caseA] = $this->openCase();
        [, , , , $caseB] = $this->openCase('partner');
        $finance = $this->user('finance_manager');
        $this->actingAs($finance, 'sanctum')->postJson("/api/finance/payment-reconciliations/{$caseB->id}/resolve", ['resolution' => 'closed_without_enrollment', 'note' => 'أُغلقت الحالة', 'refund_required' => true])->assertOk();

        $open = $this->actingAs($finance, 'sanctum')->getJson('/api/finance/payment-reconciliations?status=open')->json();
        $resolved = $this->actingAs($finance, 'sanctum')->getJson('/api/finance/payment-reconciliations?status=resolved')->json();
        $refund = $this->actingAs($finance, 'sanctum')->getJson('/api/finance/payment-reconciliations?refund=pending')->json();
        $search = $this->actingAs($finance, 'sanctum')->getJson('/api/finance/payment-reconciliations?q='.urlencode($payerA->email))->json();

        $this->assertSame([$caseA->id], array_column($open['data'], 'id'));
        $this->assertSame([$caseB->id], array_column($resolved['data'], 'id'));
        $this->assertSame([$caseB->id], array_column($refund['data'], 'id'));
        $this->assertSame([$caseA->id], array_column($search['data'], 'id'));
        foreach ([$open, $resolved, $refund, $search] as $response) {
            $this->assertSame(['open' => 1, 'resolved' => 1, 'refund_pending' => 1], $response['summary']);
        }
    }

    // ── resolving, and the refund marker ─────────────────────────────────────

    public function test_closing_with_a_refund_follow_up_marks_it_pending_and_never_marks_the_payment_refunded(): void
    {
        [, , $registration, $payment, $case] = $this->openCase();
        $finance = $this->user('finance_manager');

        $row = $this->actingAs($finance, 'sanctum')->postJson("/api/finance/payment-reconciliations/{$case->id}/resolve", [
            'resolution' => 'closed_without_enrollment', 'note' => 'يجب إعادة المبلغ من لوحة Stripe', 'refund_required' => true,
        ])->assertOk()->json('data');

        $this->assertSame('resolved', $row['status']);
        $this->assertSame('closed_without_enrollment', $row['resolution']);
        $this->assertSame('pending', $row['refund_status']);
        $this->assertSame($finance->name, $row['resolved_by']['name']);
        $this->assertSame('يجب إعادة المبلغ من لوحة Stripe', $row['resolution_note']);
        $this->assertSame([], $row['actions']);
        $this->assertSame('payment_confirmed', $payment->fresh()->status, 'the payment is never marked refunded');
        $this->assertNotSame('refunded', Order::find($payment->order_id)->status);
        $this->assertSame('cancelled', $registration->fresh()->status);
        $this->assertSame(1, Invoice::where('order_id', $payment->order_id)->count(), 'the invoice is preserved');
    }

    public function test_closing_without_a_refund_follow_up_leaves_no_refund_marker(): void
    {
        [, , , , $case] = $this->openCase();

        $row = $this->actingAs($this->user('finance_manager'), 'sanctum')->postJson("/api/finance/payment-reconciliations/{$case->id}/resolve", ['resolution' => 'closed_without_enrollment', 'note' => 'لا استرداد مطلوب'])->assertOk()->json('data');

        $this->assertNull($row['refund_status']);
    }

    public function test_a_refund_follow_up_only_makes_sense_when_closing(): void
    {
        [, , $registration, , $case] = $this->openCase();

        $this->actingAs($this->user('finance_manager'), 'sanctum')->postJson("/api/finance/payment-reconciliations/{$case->id}/resolve", ['resolution' => 'enrollment_activated', 'note' => 'تفعيل مع استرداد؟', 'refund_required' => true])
            ->assertStatus(422)->assertJsonPath('code', 'refund_requires_closing');
        $this->assertSame('open', $case->fresh()->status);
        $this->assertSame('pending_payment', $registration->fresh()->status);
    }

    public function test_there_is_no_way_to_mark_a_refund_completed(): void
    {
        [, , , , $case] = $this->openCase();
        $finance = $this->user('finance_manager');

        foreach (['refunded', 'refund_completed', 'refund_status'] as $try) {
            $this->actingAs($finance, 'sanctum')->postJson("/api/finance/payment-reconciliations/{$case->id}/resolve", ['resolution' => $try, 'note' => 'محاولة غير مسموحة'])->assertStatus(422);
        }
        $this->assertSame('open', $case->fresh()->status);
        $this->assertNull($case->fresh()->refund_status);
    }

    // ── what the payer sees ──────────────────────────────────────────────────

    public function test_the_payer_sees_payment_received_under_review_never_complete_payment(): void
    {
        [$payer, $course, , , $case] = $this->openCase();

        $row = collect($this->actingAs($payer, 'sanctum')->getJson('/api/student/registrations')->assertOk()->json('data'))->firstWhere('course_id', $course->id);

        $this->assertSame('payment_under_review', $row['access']['block_reason']);
        $this->assertTrue($row['access']['payment_under_review']);
        $this->assertFalse($row['access']['payment_required']);
        $this->assertTrue($row['access']['payment_completed']);
        $this->assertNull($row['access']['payment_url'], 'no link to pay again');
        $this->assertFalse($row['access']['can_access_learning']);

        // Paying again is refused with the same explanation.
        $this->actingAs($payer, 'sanctum')->postJson("/api/courses/{$course->id}/checkout", [])->assertStatus(409)->assertJsonPath('code', 'payment_under_review');

        // Once finance activates it (after the assignment is removed) the state is a normal enrollment.
        $course->forceFill(['instructor_id' => null])->save();
        $this->actingAs($this->user('finance_manager'), 'sanctum')->postJson("/api/finance/payment-reconciliations/{$case->id}/resolve", ['resolution' => 'enrollment_activated', 'note' => 'أُزيل الإسناد وفُعّل'])->assertOk();
        $after = collect($this->actingAs($payer, 'sanctum')->getJson('/api/student/registrations')->json('data'))->firstWhere('course_id', $course->id);
        $this->assertFalse($after['access']['payment_under_review']);
        $this->assertTrue($after['access']['can_access_learning']);
    }

    public function test_a_normal_pending_payment_is_still_complete_payment(): void
    {
        $payer = $this->user('student');
        $course = Course::factory()->paid(60)->create(['registration_open' => true, 'status' => 'published']);
        $this->actingAs($payer, 'sanctum')->postJson("/api/courses/{$course->id}/checkout", [])->assertOk();

        $row = collect($this->actingAs($payer, 'sanctum')->getJson('/api/student/registrations')->json('data'))->firstWhere('course_id', $course->id);

        $this->assertSame('payment_pending', $row['access']['block_reason']);
        $this->assertFalse($row['access']['payment_under_review']);
        $this->assertTrue($row['access']['payment_required']);
        $this->assertNotNull($row['access']['payment_url']);
    }
}
