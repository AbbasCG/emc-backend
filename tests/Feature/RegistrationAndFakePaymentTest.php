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
                'message' => 'Registration completed successfully.',
            ])
            ->assertJsonPath('registration.status', 'Registered');

        $this->assertDatabaseHas('registrations', [
            'course_id' => $course->id,
            'email' => 'jan.test@example.com',
            'status' => 'Registered',
        ]);

        $this->assertDatabaseCount('payments', 0);
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
                'message' => 'Payment required.',
            ])
            ->assertJsonPath('registration.status', 'Pending Payment')
            ->assertJsonPath('payment.provider', 'fake')
            ->assertJsonPath('payment.status', 'Pending Payment');

        $checkoutUrl = $response->json('checkout_url');
        $this->assertIsString($checkoutUrl);
        $this->assertStringContainsString('/fake-payment/', $checkoutUrl);

        $paymentId = $response->json('payment.id');
        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'provider' => 'fake',
            'status' => 'Pending Payment',
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
                'payment_status' => 'Payment Confirmed',
                'registration_status' => 'Registered',
            ]);

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => 'Payment Confirmed',
        ]);
        $this->assertDatabaseHas('registrations', [
            'email' => 'jan.test@example.com',
            'status' => 'Registered',
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
                'payment_status' => 'Payment Failed',
                'registration_status' => 'Pending Payment',
            ]);
    }

    public function test_paid_course_without_payment_provider_returns_422(): void
    {
        $course = Course::factory()->paid()->create();

        $response = $this->postJson('/api/register', $this->registrationPayload($course->id));

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
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
            'status' => 'Registered',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/registrations');

        $response->assertOk()
            ->assertJson(['success' => true]);
    }
}
