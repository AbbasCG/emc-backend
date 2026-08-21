<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationAndFakePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function registrationPayload(int $courseId, array $extra = []): array
    {
        return array_merge([
            'course_id' => $courseId,
            'full_name' => 'Jan Test',
            'phone' => '0612345678',
            'email' => 'jan.test@example.com',
            'city' => 'Amsterdam',
            'gender' => null,
            'notes' => null,
        ], $extra);
    }

    public function test_free_registration_creates_registered_without_payment(): void
    {
        Mail::fake();

        $course = Course::factory()->free()->create();

        $response = $this->postJson('/api/register', $this->registrationPayload($course->id));

        $response->assertCreated()
            ->assertJson([
                'success' => true,
                'type' => 'free',
            ])
            ->assertJsonPath('registration.status', 'registered');

        $this->assertDatabaseHas('registrations', [
            'course_id' => $course->id,
            'email' => 'jan.test@example.com',
            'status' => 'registered',
        ]);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_authenticated_student_can_join_a_free_course_without_profile_phone(): void
    {
        Mail::fake();

        $student = User::factory()->create([
            'role' => 'student',
            'phone' => null,
            'city' => null,
            'country' => null,
        ]);
        $course = Course::factory()->free()->create();

        $response = $this->actingAs($student, 'sanctum')->postJson(
            "/api/courses/{$course->id}/register",
            [],
        );

        $response->assertCreated()
            ->assertJson([
                'success' => true,
                'type' => 'free',
            ])
            ->assertJsonPath('registration.status', 'registered');

        $this->assertDatabaseHas('registrations', [
            'course_id' => $course->id,
            'user_id' => $student->id,
            'email' => $student->email,
            'phone' => null,
            'status' => 'registered',
        ]);
    }

    public function test_paid_registration_with_fake_creates_pending_payment_and_checkout_url(): void
    {
        Mail::fake();

        $course = Course::factory()->paid(49.99)->create();

        $response = $this->postJson('/api/register', $this->registrationPayload($course->id, [
            'payment_provider' => 'fake',
        ]));

        $response->assertCreated()
            ->assertJson([
                'success' => true,
                'type' => 'paid',
            ])
            ->assertJsonPath('registration.status', 'pending_payment')
            ->assertJsonPath('payment.provider', 'fake')
            ->assertJsonPath('payment.status', 'pending_payment');

        $checkoutUrl = $response->json('checkout_url');
        $this->assertIsString($checkoutUrl);
        $this->assertStringContainsString('/fake-payment/', $checkoutUrl);

        $paymentId = $response->json('payment.id');
        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'provider' => 'fake',
            'status' => 'pending_payment',
        ]);
    }

    public function test_fake_confirm_marks_payment_confirmed_and_registration_registered(): void
    {
        Mail::fake();

        $course = Course::factory()->paid(10)->create();

        $register = $this->postJson('/api/register', $this->registrationPayload($course->id, [
            'payment_provider' => 'fake',
        ]));
        $paymentId = $register->json('payment.id');

        $response = $this->postJson("/api/payments/fake/confirm/{$paymentId}");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'payment_status' => 'payment_confirmed',
                'registration_status' => 'registered',
            ]);

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => 'payment_confirmed',
        ]);
        $this->assertDatabaseHas('registrations', [
            'email' => 'jan.test@example.com',
            'status' => 'registered',
        ]);
    }

    public function test_fake_fail_marks_payment_failed_and_keeps_registration_pending(): void
    {
        Mail::fake();

        $course = Course::factory()->paid(10)->create();

        $register = $this->postJson('/api/register', $this->registrationPayload($course->id, [
            'payment_provider' => 'fake',
        ]));
        $paymentId = $register->json('payment.id');

        $response = $this->postJson("/api/payments/fake/fail/{$paymentId}");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'payment_status' => 'payment_failed',
                'registration_status' => 'pending_payment',
            ]);
    }

    public function test_paid_course_without_payment_provider_requires_checkout(): void
    {
        $course = Course::factory()->paid()->create();

        $response = $this->postJson('/api/register', $this->registrationPayload($course->id));

        $response->assertStatus(402)
            ->assertJson([
                'success' => false,
                'requires_checkout' => true,
                'checkout_endpoint' => "/api/courses/{$course->id}/checkout",
            ]);
    }

    public function test_invalid_payment_provider_returns_422(): void
    {
        $course = Course::factory()->paid()->create();

        $response = $this->postJson('/api/register', $this->registrationPayload($course->id, [
            'payment_provider' => 'bitcoin',
        ]));

        $response->assertStatus(422);
    }

    public function test_fake_provider_rejected_when_not_allowed_in_testing(): void
    {
        config(['app.allow_fake_payments_in_testing' => false]);

        $course = Course::factory()->paid()->create();

        $response = $this->postJson('/api/register', $this->registrationPayload($course->id, [
            'payment_provider' => 'fake',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_provider']);
    }

    public function test_admin_can_list_registrations_when_authenticated(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->free()->create();
        Registration::factory()->create([
            'course_id' => $course->id,
            'status' => 'registered',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/registrations');

        $response->assertOk()
            ->assertJson(['success' => true]);
    }
}
