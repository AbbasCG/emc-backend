<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\LmsSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReconciliation;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\FakesStripe;
use Tests\TestCase;

/**
 * Every way of putting someone in front of a program as its instructor refuses a person who is enrolled in that program as a
 * learner: the course's primary instructor, a scheduled workshop, a class group, an LMS session and a learning path.
 * A payment still in progress is allowed through and settled by the payment webhook (reconciliation); learner records of the past
 * and legitimate assignments are untouched.
 */
class InstructorAssignmentConsistencyTest extends TestCase
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

    private function user(string $role = 'volunteer'): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function profile(User $user): Instructor
    {
        return Instructor::firstOrCreate(['user_id' => $user->id], ['name' => $user->name]);
    }

    private function course(array $attributes = []): Course
    {
        return Course::factory()->free()->create(array_merge(['registration_open' => true, 'status' => 'published'], $attributes));
    }

    private function enrolled(Course $course, string $status = 'registered', string $role = 'volunteer'): User
    {
        $user = $this->user($role);
        Registration::factory()->create(['user_id' => $user->id, 'course_id' => $course->id, 'status' => $status]);

        return $user;
    }

    private function assertRefused(callable $assign): void
    {
        try {
            $assign();
            $this->fail('the assignment should have been refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('instructor_id', $e->errors());
            $this->assertStringContainsString('مسجّل فيه كمتعلّم', $e->errors()['instructor_id'][0]);
        }
    }

    // ── the five mechanisms ──────────────────────────────────────────────────

    public function test_a_course_primary_instructor_cannot_be_an_enrolled_learner(): void
    {
        $course = $this->course();
        $learner = $this->enrolled($course);

        $this->assertRefused(fn () => $course->update(['instructor_id' => $this->profile($learner)->id]));
        $this->assertNull($course->fresh()->instructor_id);
    }

    public function test_a_scheduled_workshop_cannot_be_taught_by_an_enrolled_learner_of_its_course(): void
    {
        $course = $this->course(['program_type' => 'workshop']);
        $learner = $this->enrolled($course);

        $this->assertRefused(fn () => Workshop::factory()->create(['course_id' => $course->id, 'instructor_id' => $this->profile($learner)->id]));
        $this->assertSame(0, Workshop::count());

        $workshop = Workshop::factory()->create(['course_id' => $course->id, 'instructor_id' => null]);
        $this->assertRefused(fn () => $workshop->update(['instructor_id' => $this->profile($learner)->id]));
        $this->assertNull($workshop->fresh()->instructor_id);
    }

    public function test_a_class_group_cannot_be_taught_by_an_enrolled_learner_of_its_course_or_any_course_it_serves(): void
    {
        $course = $this->course();
        $other = $this->course();
        $learner = $this->enrolled($course);
        $otherLearner = $this->enrolled($other);
        $attrs = ['name' => 'مجموعة', 'level_code' => 'A1', 'capacity' => 10, 'status' => 'active'];

        $this->assertRefused(fn () => ClassGroup::create($attrs + ['course_id' => $course->id, 'instructor_id' => $this->profile($learner)->id]));

        $group = ClassGroup::create($attrs + ['course_id' => $course->id]);
        $group->courses()->attach($other->id);
        $this->assertRefused(fn () => $group->update(['instructor_id' => $this->profile($otherLearner)->id]));
        $this->assertNull($group->fresh()->instructor_id);
    }

    public function test_an_lms_session_cannot_be_taught_by_an_enrolled_learner_directly_or_through_its_workshop(): void
    {
        $course = $this->course();
        $learner = $this->enrolled($course);
        $workshop = Workshop::factory()->create(['course_id' => $course->id, 'instructor_id' => null]);
        $session = ['title' => 'جلسة', 'session_date' => now()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled'];

        $this->assertRefused(fn () => LmsSession::create($session + ['course_id' => $course->id, 'instructor_id' => $this->profile($learner)->id]));
        $this->assertRefused(fn () => LmsSession::create($session + ['workshop_id' => $workshop->id, 'instructor_id' => $this->profile($learner)->id]));
        $this->assertSame(0, LmsSession::count());
    }

    public function test_a_learning_path_cannot_be_taught_by_a_learner_of_the_path_or_of_a_course_in_it(): void
    {
        $course = $this->course();
        $path = LearningPath::create([
            'title' => 'مسار', 'slug' => 'p-'.uniqid(), 'status' => 'published', 'enrollment_open' => true, 'price' => 0, 'currency' => 'EUR', 'duration' => 1,
            'duration_unit' => 'weeks', 'language' => 'ar', 'level' => 'beginner', 'short_description' => 'x', 'full_description' => 'x',
        ]);
        $path->courses()->attach($course->id, ['sort_order' => 1]);

        $courseLearner = $this->enrolled($course);
        $this->assertRefused(fn () => $path->update(['instructor_id' => $this->profile($courseLearner)->id]));

        $pathLearner = $this->user('partner');
        LearningPathEnrollment::create(['learning_path_id' => $path->id, 'user_id' => $pathLearner->id, 'status' => 'active']);
        $this->assertRefused(fn () => $path->update(['instructor_id' => $this->profile($pathLearner)->id]));
        $this->assertNull($path->fresh()->instructor_id);
    }

    // ── what stays allowed ───────────────────────────────────────────────────

    public function test_legitimate_assignments_and_historical_learner_records_are_untouched(): void
    {
        $course = $this->course();
        $someoneElse = $this->user('instructor');
        $cancelled = $this->enrolled($course, 'cancelled');
        $noShow = $this->enrolled($course, 'no_show');
        $elsewhere = $this->enrolled($this->course());
        $pending = $this->enrolled($course, 'pending_payment');

        foreach ([$someoneElse, $cancelled, $noShow, $elsewhere, $pending] as $teacher) {
            $c = $this->course();
            if ($teacher === $cancelled || $teacher === $noShow || $teacher === $pending) {
                Registration::where('user_id', $teacher->id)->update(['course_id' => $c->id]);
            }
            $c->update(['instructor_id' => $this->profile($teacher)->id]);
            $this->assertNotNull($c->fresh()->instructor_id);
        }
        // Their old learner records are exactly as they were.
        $this->assertSame('cancelled', Registration::where('user_id', $cancelled->id)->value('status'));
        $this->assertSame('no_show', Registration::where('user_id', $noShow->id)->value('status'));

        // Changing something else about a course that already has its instructor never re-triggers the check.
        $taught = $this->course(['instructor_id' => $this->profile($someoneElse)->id]);
        $taught->update(['title' => 'عنوان جديد']);
        $this->assertSame('عنوان جديد', $taught->fresh()->title);
    }

    public function test_the_admin_api_refuses_the_assignment_with_a_clear_message(): void
    {
        $course = $this->course();
        $learner = $this->enrolled($course);
        $admin = $this->user('admin');

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/courses/{$course->id}", ['instructor_id' => $this->profile($learner)->id]);

        $response->assertStatus(422)->assertJsonValidationErrors('instructor_id');
        $this->assertNull($course->fresh()->instructor_id);
    }

    // ── pending payments: the existing reconciliation workflow, for every mechanism ──

    /** @return array{0: User, 1: Course, 2: Order, 3: Registration, 4: Payment} */
    private function payerAtCheckout(): array
    {
        $payer = $this->user('hr_manager');
        $course = Course::factory()->paid(60)->create(['registration_open' => true, 'status' => 'published']);
        $this->actingAs($payer, 'sanctum')->postJson("/api/courses/{$course->id}/checkout", [])->assertOk();

        return [$payer, $course, Order::where('user_id', $payer->id)->firstOrFail(), Registration::where('user_id', $payer->id)->firstOrFail(), Payment::latest('id')->firstOrFail()];
    }

    private function confirm(User $payer, Course $course, Order $order, Registration $registration, Payment $payment)
    {
        return $this->stripeWebhook('checkout.session.completed', $payment->provider_payment_id, [
            'order_id' => (string) $order->id, 'registration_id' => (string) $registration->id, 'user_id' => (string) $payer->id, 'course_id' => (string) $course->id,
        ], $this->paidObject());
    }

    public function test_a_pending_payer_assigned_through_any_mechanism_is_reconciled_when_the_payment_arrives(): void
    {
        $mechanisms = [
            'workshop'    => fn (Course $c, Instructor $p) => Workshop::factory()->create(['course_id' => $c->id, 'instructor_id' => $p->id]),
            'class group' => fn (Course $c, Instructor $p) => ClassGroup::create(['name' => 'م', 'level_code' => 'A1', 'capacity' => 10, 'status' => 'active', 'course_id' => $c->id, 'instructor_id' => $p->id]),
            'session'     => fn (Course $c, Instructor $p) => LmsSession::create(['title' => 'ج', 'session_date' => now()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled', 'course_id' => $c->id, 'instructor_id' => $p->id]),
        ];

        foreach ($mechanisms as $name => $assign) {
            [$payer, $course, $order, $registration, $payment] = $this->payerAtCheckout();
            $assign($course, $this->profile($payer)); // allowed: only a pending payment so far

            $this->confirm($payer, $course, $order, $registration, $payment)->assertOk();

            $this->assertSame('pending_payment', $registration->fresh()->status, "[{$name}] enrollment withheld");
            $this->assertSame('payment_confirmed', $payment->fresh()->status, "[{$name}] money recorded");
            $this->assertSame(1, PaymentReconciliation::where('payment_id', $payment->id)->count(), "[{$name}] one open item");
        }
    }

    public function test_assignment_after_the_enrollment_became_active_is_refused_and_the_other_order_withholds(): void
    {
        // Order 1: payment confirmed first (enrolled), then the assignment is refused.
        [$payer, $course, $order, $registration, $payment] = $this->payerAtCheckout();
        $this->confirm($payer, $course, $order, $registration, $payment)->assertOk();
        $this->assertSame('registered', $registration->fresh()->status);
        $this->assertRefused(fn () => $course->update(['instructor_id' => $this->profile($payer)->id]));

        // Order 2 (assignment first, then the payment) is the previous test and the reconciliation tests.
        $this->assertSame(0, PaymentReconciliation::count());
    }

    /**
     * Found on a real MariaDB (REPEATABLE READ): the guard used to read the instructor profile BEFORE locking the course rows. That
     * plain SELECT fixed the transaction's snapshot, so after waiting for a concurrent enrollment / payment confirmation to commit
     * the guard still read the world as it was before it, and let the assignment through (one learner who also teaches in about
     * one concurrent round in ten). The course rows must be the first thing the guard touches. SQLite cannot reproduce the race,
     * so the order of the guard's own queries is what is pinned here.
     */
    public function test_the_guard_locks_the_course_rows_before_reading_anything_else(): void
    {
        $course = $this->course();
        $teacher = $this->profile($this->user());

        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        \Illuminate\Support\Facades\DB::transaction(fn () => app(\App\Services\LearnerEnrollmentGuard::class)->assertMayTeach($teacher->id, [$course->id]));

        $selects = array_values(array_filter($queries, fn (string $sql) => str_starts_with($sql, 'select')));
        $this->assertNotEmpty($selects);
        $this->assertStringContainsString('from "courses"', $selects[0], 'the first read must be the course lock, not a plain read that fixes the snapshot');
    }
}
