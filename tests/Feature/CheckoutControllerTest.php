<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Models\Course;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root cause: CheckoutController::checkout() built the Registration payload
 * with a 'name' key — not in Registration::$fillable (which only has
 * 'full_name') — so it was silently dropped, and 'phone' was never set at
 * all. registrations.full_name is NOT NULL with no default and (before this
 * fix) so was registrations.phone, so every paid-course checkout attempt
 * hit a SQLSTATE 23000 "Field 'full_name' doesn't have a default value" /
 * "phone doesn't have a default value" QueryException, caught by the
 * generic catch-all and surfaced to the frontend as a bare 500 with
 * "تعذر إنشاء جلسة الدفع". Fixed by using 'full_name' (matches $fillable)
 * and making 'phone' nullable (matches 'city'/'country', which were already
 * nullable) since the one-click checkout flow has no form to collect a
 * phone number from a user who never put one on their profile.
 */
class CheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    private function student(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'student', 'is_active' => true, 'phone' => null, 'city' => null, 'country' => null,
        ], $overrides));
    }

    private function paidCourse(array $overrides = []): Course
    {
        return Course::factory()->paid()->create(array_merge([
            'status' => 'published',
        ], $overrides));
    }

    // ── The actual reported bug: registration creation must not blow up on NOT NULL columns ──

    public function test_registration_can_be_created_with_the_exact_checkout_payload_and_a_user_with_no_phone_city_or_country(): void
    {
        $user   = $this->student();
        $course = $this->paidCourse();

        // Mirrors CheckoutController::checkout()'s Registration::create() call exactly.
        $registration = Registration::create([
            'user_id'   => $user->id,
            'course_id' => $course->id,
            'email'     => $user->email,
            'full_name' => $user->name,
            'phone'     => $user->phone ?? null,
            'city'      => $user->city ?? null,
            'country'   => $user->country ?? null,
            'status'    => RegistrationStatus::PENDING_PAYMENT->value,
        ]);

        $this->assertDatabaseHas('registrations', [
            'id'        => $registration->id,
            'full_name' => $user->name,
            'phone'     => null,
            'city'      => null,
            'country'   => null,
            'status'    => 'pending_payment',
        ]);
    }

    public function test_registration_full_name_is_correctly_populated_not_silently_dropped(): void
    {
        $user   = $this->student(['name' => 'Faisal Al-Rashidi']);
        $course = $this->paidCourse();

        $registration = Registration::create([
            'user_id'   => $user->id,
            'course_id' => $course->id,
            'email'     => $user->email,
            'full_name' => $user->name,
            'status'    => RegistrationStatus::PENDING_PAYMENT->value,
        ]);

        $this->assertSame('Faisal Al-Rashidi', $registration->fresh()->full_name);
    }

    // ── Guard clauses now returning controlled, stable-shaped errors ──

    public function test_checkout_on_a_free_course_returns_422_with_stable_error_code(): void
    {
        $user   = $this->student();
        $course = Course::factory()->create(['type' => 'free', 'price' => 0, 'status' => 'published']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$course->id}/checkout");

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'course_not_paid']);
    }

    public function test_checkout_when_registration_closed_returns_422_with_stable_error_code(): void
    {
        $user   = $this->student();
        $course = $this->paidCourse(['registration_open' => false]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$course->id}/checkout");

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'registration_closed']);
    }

    public function test_checkout_with_invalid_course_price_returns_422_instead_of_a_bare_500(): void
    {
        $user   = $this->student();
        $course = $this->paidCourse(['price' => 0]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$course->id}/checkout");

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'invalid_course_price']);
    }

    public function test_checkout_when_already_enrolled_returns_422_with_stable_error_code(): void
    {
        $user   = $this->student();
        $course = $this->paidCourse();
        Registration::create([
            'user_id' => $user->id, 'course_id' => $course->id, 'email' => $user->email,
            'full_name' => $user->name, 'status' => RegistrationStatus::REGISTERED->value,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$course->id}/checkout");

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'already_enrolled']);
    }

    public function test_checkout_with_no_stripe_secret_configured_returns_controlled_503_not_a_bare_500(): void
    {
        config(['services.stripe.secret' => '']);

        $user   = $this->student();
        $course = $this->paidCourse();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$course->id}/checkout");

        $response->assertStatus(503)
            ->assertJson(['success' => false, 'code' => 'stripe_not_configured']);

        // No leftover pending registration/order from the blocked attempt.
        $this->assertDatabaseMissing('registrations', ['user_id' => $user->id, 'course_id' => $course->id]);
    }

    public function test_no_stripe_secret_guard_runs_before_any_database_writes(): void
    {
        config(['services.stripe.secret' => null]);

        $user   = $this->student();
        $course = $this->paidCourse();

        $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$course->id}/checkout")->assertStatus(503);

        $this->assertSame(0, Registration::where('course_id', $course->id)->count());
        $this->assertDatabaseCount('orders', 0);
    }
}
