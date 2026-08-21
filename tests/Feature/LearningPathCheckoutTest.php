<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\LearningPathItem;
use App\Models\Order;
use App\Models\User;
use App\Services\LearningPathEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningPathCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_path_enroll_requires_checkout_without_granting_course_access(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);
        [$path, $course] = $this->pathWithCourse(149, 'approved');

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/learning-paths/{$path->slug}/enroll")
            ->assertStatus(402)
            ->assertJsonPath('requires_checkout', true);

        $this->assertDatabaseMissing('learning_path_enrollments', [
            'learning_path_id' => $path->id,
            'user_id' => $student->id,
        ]);
        $this->assertDatabaseMissing('registrations', [
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]);
        $this->assertDatabaseMissing('student_progress', [
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]);
    }

    public function test_free_path_enrolls_and_provisions_its_courses(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);
        [$path, $course] = $this->pathWithCourse(0, 'not_required');

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/learning-paths/{$path->slug}/enroll")
            ->assertCreated()
            ->assertJsonPath('enrollment.status', 'active');

        $this->assertDatabaseHas('learning_path_enrollments', [
            'learning_path_id' => $path->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('registrations', [
            'learning_path_id' => $path->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => 'registered',
        ]);
        $this->assertDatabaseHas('student_progress', [
            'course_id' => $course->id,
            'user_id' => $student->id,
            'progress_percentage' => 0,
        ]);
    }

    public function test_pending_path_only_grants_courses_when_payment_is_activated(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);
        [$path, $course] = $this->pathWithCourse(149, 'approved');
        $order = Order::create([
            'user_id' => $student->id,
            'learning_path_id' => $path->id,
            'order_number' => Order::generateOrderNumber(),
            'type' => 'learning_path',
            'subtotal' => 149,
            'total' => 149,
            'currency' => 'EUR',
            'status' => 'pending',
            'payment_provider' => 'stripe',
        ]);

        $service = app(LearningPathEnrollmentService::class);
        $pending = $service->createPendingPayment($student, $path, $order);

        $this->assertSame('pending_payment', $pending->status);
        $this->assertNull($pending->enrolled_at);
        $this->assertDatabaseMissing('registrations', [
            'course_id' => $course->id,
            'user_id' => $student->id,
        ]);

        $active = $service->activate($pending);

        $this->assertSame('active', $active->status);
        $this->assertNotNull($active->enrolled_at);
        $this->assertDatabaseHas('registrations', [
            'learning_path_id' => $path->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => 'registered',
        ]);
    }

    public function test_paid_path_checkout_requires_finance_approval(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);
        [$path] = $this->pathWithCourse(149, 'pending');

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/learning-paths/{$path->slug}/checkout")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'finance_approval_required');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('learning_path_enrollments', 0);
    }

    public function test_paid_path_checkout_fails_safely_when_stripe_is_not_configured(): void
    {
        config()->set('services.stripe.secret', '');
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);
        [$path] = $this->pathWithCourse(149, 'approved');

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/learning-paths/{$path->slug}/checkout")
            ->assertStatus(503)
            ->assertJsonPath('code', 'stripe_not_configured');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('learning_path_enrollments', 0);
    }

    public function test_public_catalog_hides_paid_programs_until_finance_approval(): void
    {
        [$pendingPath] = $this->pathWithCourse(149, 'pending');
        $approvedPath = LearningPath::create([
            'title' => 'مسار مالي معتمد',
            'slug' => 'approved-public-path',
            'status' => 'published',
            'enrollment_open' => true,
            'duration_unit' => 'weeks',
            'price' => 199,
            'currency' => 'EUR',
            'finance_approval_status' => 'approved',
        ]);
        $pendingCourse = Course::factory()->paid(80)->create([
            'status' => 'published',
            'finance_approval_status' => 'pending',
        ]);
        $approvedCourse = Course::factory()->paid(90)->create([
            'status' => 'published',
            'finance_approval_status' => 'approved',
        ]);

        $paths = $this->getJson('/api/learning-paths?per_page=50')->assertOk();
        $pathIds = collect($paths->json('data'))->pluck('id');
        $this->assertFalse($pathIds->contains($pendingPath->id));
        $this->assertTrue($pathIds->contains($approvedPath->id));

        $courses = $this->getJson('/api/courses?per_page=200')->assertOk();
        $courseIds = collect($courses->json('data'))->pluck('id');
        $this->assertFalse($courseIds->contains($pendingCourse->id));
        $this->assertTrue($courseIds->contains($approvedCourse->id));
    }

    /** @return array{LearningPath, Course} */
    private function pathWithCourse(float $price, string $financeStatus): array
    {
        $course = Course::factory()->create([
            'status' => 'published',
            'registration_open' => true,
        ]);
        $path = LearningPath::create([
            'title' => 'مسار الاختبار ' . uniqid(),
            'slug' => 'checkout-path-' . uniqid(),
            'status' => 'published',
            'enrollment_open' => true,
            'duration_unit' => 'weeks',
            'price' => $price,
            'currency' => 'EUR',
            'finance_approval_status' => $financeStatus,
        ]);
        LearningPathItem::create([
            'learning_path_id' => $path->id,
            'course_id' => $course->id,
            'sort_order' => 0,
        ]);

        return [$path, $course];
    }
}
