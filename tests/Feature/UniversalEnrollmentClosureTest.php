<?php

namespace Tests\Feature;

use App\Models\Course;
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
 * A program that cannot take a new enrollment (closed, unpublished, ended or full) takes none: free or paid, from any role, through
 * either public entry point, by a direct request. What already exists is untouched: existing registrations keep their access, a
 * payment in flight is still honoured, and an administrator enrolling someone by hand remains a management action.
 */
class UniversalEnrollmentClosureTest extends TestCase
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

    private function userWithRole(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function free(array $attributes = []): Course
    {
        return Course::factory()->free()->create(array_merge(['registration_open' => true, 'status' => 'published'], $attributes));
    }

    private function paid(array $attributes = []): Course
    {
        return Course::factory()->paid(40)->create(array_merge(['registration_open' => true, 'status' => 'published'], $attributes));
    }

    private function register(?User $user, Course $course)
    {
        if ($user) {
            return $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$course->id}/register", []);
        }
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/register', ['course_id' => $course->id, 'full_name' => 'زائر', 'email' => 'guest@example.com', 'phone' => '+31600000000']);
    }

    private function checkout(User $user, Course $course)
    {
        return $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$course->id}/checkout", []);
    }

    private function assertRefused($response, string $code): void
    {
        $response->assertStatus(422)->assertJsonPath('code', $code);
        $this->assertSame($response->json('message'), $response->json('message_ar'));
    }

    // ── closed ───────────────────────────────────────────────────────────────

    #[DataProvider('everyRole')]
    public function test_a_closed_program_takes_no_new_registration_free_or_paid_from_any_role(string $role): void
    {
        $user = $this->userWithRole($role);
        $free = $this->free(['registration_open' => false]);
        $paid = $this->paid(['registration_open' => false]);

        $this->assertRefused($this->register($user, $free), 'registration_closed');
        $this->assertRefused($this->checkout($user, $paid), 'registration_closed');
        $this->assertSame(0, Registration::where('user_id', $user->id)->count());
        $this->assertSame([], $this->stripeCalls, 'Stripe is never reached for a closed program');
    }

    public function test_a_guest_cannot_register_in_a_closed_unpublished_or_full_program_by_a_direct_request(): void
    {
        $this->assertRefused($this->register(null, $this->free(['registration_open' => false])), 'registration_closed');
        $this->assertRefused($this->register(null, $this->free(['status' => 'draft'])), 'program_unavailable');
        $full = $this->free(['capacity' => 1]);
        Registration::factory()->create(['course_id' => $full->id, 'user_id' => $this->userWithRole('student')->id, 'status' => 'registered']);
        $this->assertRefused($this->register(null, $full), 'program_full');
        $this->assertSame(1, Registration::count());
    }

    public function test_a_closed_workshop_says_workshop(): void
    {
        $workshop = $this->free(['program_type' => 'workshop', 'registration_open' => false]);

        $response = $this->register($this->userWithRole('volunteer'), $workshop);

        $this->assertRefused($response, 'registration_closed');
        $this->assertStringContainsString('الورشة', (string) $response->json('message'));
    }

    // ── unpublished ──────────────────────────────────────────────────────────

    #[DataProvider('everyRole')]
    public function test_an_unpublished_program_takes_nobody_free_or_paid(string $role): void
    {
        $user = $this->userWithRole($role);

        foreach (['draft', 'cancelled', 'completed', 'inactive'] as $status) {
            $this->assertRefused($this->register($user, $this->free(['status' => $status])), 'program_unavailable');
            $this->assertRefused($this->checkout($user, $this->paid(['status' => $status])), 'program_unavailable');
        }
        $this->assertSame(0, Registration::where('user_id', $user->id)->count());
    }

    // ── ended ────────────────────────────────────────────────────────────────

    public function test_an_ended_program_takes_nobody(): void
    {
        $user = $this->userWithRole('partner');
        $ended = ['end_date' => now()->subDays(3)->toDateString(), 'start_date' => now()->subDays(30)->toDateString()];

        $this->assertRefused($this->register($user, $this->free($ended + ['registration_open' => false])), 'program_ended');
        $this->assertRefused($this->checkout($user, $this->paid($ended + ['registration_open' => false])), 'program_ended');

        // Ended programs never take registrations, even if registration_open was set to true
        $this->assertRefused($this->register($user, $this->free($ended + ['registration_open' => true])), 'program_ended');
    }

    // ── full ─────────────────────────────────────────────────────────────────

    #[DataProvider('everyRole')]
    public function test_a_full_program_takes_nobody_free_or_paid_and_seats_are_counted_correctly(string $role): void
    {
        $user = $this->userWithRole($role);
        $free = $this->free(['capacity' => 2]);
        $paid = $this->paid(['capacity' => 2]);
        foreach ([$free, $paid] as $course) {
            Registration::factory()->create(['course_id' => $course->id, 'user_id' => $this->userWithRole('student')->id, 'status' => 'registered']);
            // A payment in progress holds its seat; a cancelled registration does not.
            Registration::factory()->create(['course_id' => $course->id, 'user_id' => $this->userWithRole('student')->id, 'status' => 'pending_payment']);
            Registration::factory()->create(['course_id' => $course->id, 'user_id' => $this->userWithRole('student')->id, 'status' => 'cancelled']);
        }

        $this->assertRefused($this->register($user, $free), 'program_full');
        $this->assertRefused($this->checkout($user, $paid), 'program_full');
        $this->assertSame(0, Registration::where('user_id', $user->id)->count());
    }

    public function test_the_last_seat_is_taken_once_and_a_cancelled_seat_is_free_again(): void
    {
        $course = $this->free(['capacity' => 1]);
        $first = $this->userWithRole('volunteer');
        $second = $this->userWithRole('partner');

        $this->register($first, $course)->assertCreated();
        $this->assertRefused($this->register($second, $course), 'program_full');

        Registration::where('user_id', $first->id)->update(['status' => 'cancelled']);
        $this->register($second, $course)->assertCreated();
    }

    public function test_an_unlimited_program_has_no_capacity_limit(): void
    {
        $course = $this->free(['capacity' => null]);
        foreach (range(1, 5) as $i) {
            Registration::factory()->create(['course_id' => $course->id, 'user_id' => $this->userWithRole('student')->id, 'status' => 'registered']);
        }

        $this->register($this->userWithRole('hr_manager'), $course)->assertCreated();
    }

    public function test_a_learner_retrying_their_own_abandoned_payment_is_not_refused_for_the_seat_they_already_hold(): void
    {
        $course = $this->paid(['capacity' => 1]);
        $user = $this->userWithRole('finance_manager');

        $this->checkout($user, $course)->assertOk();
        // The course is now full of exactly this learner's pending seat; their retry is still theirs.
        $this->checkout($user, $course)->assertOk();
        $this->assertSame(1, Registration::where('course_id', $course->id)->count());
        // Someone else cannot take it.
        $this->assertRefused($this->checkout($this->userWithRole('partner'), $course), 'program_full');
    }

    // ── what is preserved ────────────────────────────────────────────────────

    public function test_existing_registrations_keep_their_access_when_the_program_closes_or_fills(): void
    {
        $course = $this->free(['capacity' => 1]);
        $user = $this->userWithRole('volunteer');
        $this->register($user, $course)->assertCreated();

        $course->update(['registration_open' => false]);

        $this->assertSame([$course->id], array_column($this->actingAs($user, 'sanctum')->getJson('/api/student/courses')->assertOk()->json('data'), 'course_id'));
        // Registering again says "already registered", not "closed".
        $this->register($user, $course)->assertStatus(409);
    }

    public function test_a_payment_already_in_flight_is_still_honoured_after_the_program_closes(): void
    {
        $course = $this->paid(['capacity' => 5]);
        $user = $this->userWithRole('marketing_manager');
        $this->checkout($user, $course)->assertOk();
        $registration = Registration::where('user_id', $user->id)->firstOrFail();
        $order = \App\Models\Order::where('user_id', $user->id)->firstOrFail();
        $payment = \App\Models\Payment::where('order_id', $order->id)->firstOrFail();

        $course->update(['registration_open' => false, 'status' => 'completed']);
        $this->stripeWebhook('checkout.session.completed', $payment->provider_payment_id, ['order_id' => (string) $order->id, 'registration_id' => (string) $registration->id, 'user_id' => (string) $user->id, 'course_id' => (string) $course->id], $this->paidObject())->assertOk();

        $this->assertSame('registered', $registration->fresh()->status);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_an_administrator_can_still_enroll_someone_by_hand_into_a_closed_program(): void
    {
        $admin = $this->userWithRole('admin');
        $closed = $this->free(['registration_open' => false]);
        $person = $this->userWithRole('volunteer');

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/courses/{$closed->id}/students", ['user_id' => $person->id])->assertCreated();

        $this->assertSame('volunteer', $person->fresh()->role);
    }

    public function test_the_english_institute_checkout_refuses_a_closed_or_unpublished_program_but_not_a_full_one(): void
    {
        $student = $this->userWithRole('student');

        $this->actingAs($student, 'sanctum')->postJson('/api/institute/student/checkout', ['course_id' => $this->free(['registration_open' => false])->id])->assertStatus(422)->assertJsonPath('code', 'registration_closed');
        $this->actingAs($student, 'sanctum')->postJson('/api/institute/student/checkout', ['course_id' => $this->free(['status' => 'draft'])->id])->assertStatus(422)->assertJsonPath('code', 'program_unavailable');

        // It is the waitlist flow: capacity does not apply to it.
        $full = $this->free(['capacity' => 1]);
        Registration::factory()->create(['course_id' => $full->id, 'user_id' => $this->userWithRole('partner')->id, 'status' => 'registered']);
        $this->actingAs($student, 'sanctum')->postJson('/api/institute/student/checkout', ['course_id' => $full->id])->assertOk();
    }
}
