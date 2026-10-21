<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use App\Services\EnrollmentAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * What a visitor is shown about seats is computed with the very definition registration enforces: a seat is held by an enrollment
 * that grants access or by a payment in progress, never by a cancelled (or no-show) registration; no capacity means no limit.
 */
class CapacityIndicatorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function learner(string $role = 'volunteer'): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function fill(Course $course, array $statuses): void
    {
        foreach ($statuses as $status) {
            Registration::factory()->create(['course_id' => $course->id, 'user_id' => $this->learner()->id, 'status' => $status]);
        }
    }

    private function publicCourse(string $slug): array
    {
        return $this->getJson("/api/courses/{$slug}")->assertOk()->json('data');
    }

    public function test_public_indicators_hold_a_seat_for_access_and_for_a_payment_in_progress_but_not_for_cancelled_rows(): void
    {
        $course = Course::factory()->free()->create(['status' => 'published', 'registration_open' => true, 'capacity' => 5]);
        $this->fill($course, ['registered', 'payment_confirmed', 'attended', 'pending_payment', 'cancelled', 'cancelled', 'no_show']);

        $data = $this->publicCourse($course->slug);

        $this->assertSame(4, $data['seats_taken']);
        $this->assertSame(1, $data['seats_remaining']);
        $this->assertSame(4, $data['effective_enrollment_count']);
        $this->assertFalse($data['is_full']);
        $this->assertSame(7, $data['registrations_count'], 'the raw count of rows is still there for the admin screens');
        $this->assertSame(4, app(EnrollmentAvailability::class)->seatsTaken($course), 'enforcement counts the same');
    }

    public function test_the_indicator_and_the_enforcement_agree_at_the_last_seat(): void
    {
        $course = Course::factory()->free()->create(['status' => 'published', 'registration_open' => true, 'capacity' => 2]);
        $this->fill($course, ['registered', 'cancelled']);

        $this->assertSame(1, $this->publicCourse($course->slug)['seats_remaining']);
        $this->actingAs($this->learner(), 'sanctum')->postJson("/api/courses/{$course->id}/register", [])->assertCreated();

        $after = $this->publicCourse($course->slug);
        $this->assertTrue($after['is_full']);
        $this->assertSame(0, $after['seats_remaining']);
        $this->actingAs($this->learner(), 'sanctum')->postJson("/api/courses/{$course->id}/register", [])->assertStatus(422)->assertJsonPath('code', 'program_full');

        // A cancelled registration frees its seat in both places at once.
        Registration::where('course_id', $course->id)->where('status', 'registered')->first()->update(['status' => 'cancelled']);
        $this->assertFalse($this->publicCourse($course->slug)['is_full']);
        $this->actingAs($this->learner(), 'sanctum')->postJson("/api/courses/{$course->id}/register", [])->assertCreated();
    }

    public function test_a_course_without_capacity_shows_no_limit(): void
    {
        $course = Course::factory()->free()->create(['status' => 'published', 'registration_open' => true, 'capacity' => null]);
        $this->fill($course, ['registered', 'registered', 'registered']);

        $data = $this->publicCourse($course->slug);

        $this->assertNull($data['seats_remaining']);
        $this->assertFalse($data['is_full']);
        $this->assertSame(3, $data['seats_taken']);
    }

    public function test_the_listing_and_the_workshop_endpoints_use_the_same_numbers(): void
    {
        $course = Course::factory()->free()->create(['status' => 'published', 'registration_open' => true, 'capacity' => 3]);
        $workshop = Course::factory()->free()->create(['status' => 'published', 'registration_open' => true, 'capacity' => 3, 'program_type' => 'workshop']);
        foreach ([$course, $workshop] as $c) {
            $this->fill($c, ['registered', 'pending_payment', 'cancelled', 'cancelled']);
        }

        $listed = collect($this->getJson('/api/courses')->assertOk()->json('data'))->firstWhere('slug', $course->slug);
        $this->assertSame(2, $listed['seats_taken']);
        $this->assertSame(1, $listed['seats_remaining']);

        $workshops = collect($this->getJson('/api/workshops')->assertOk()->json('data'))->firstWhere('slug', $workshop->slug);
        $this->assertSame(2, $workshops['enrolled_count']);
        $this->assertSame(1, $workshops['spots_remaining']);
        $this->assertFalse($workshops['is_full']);

        $detail = $this->getJson("/api/workshops/{$workshop->slug}")->assertOk()->json('data');
        $this->assertSame(2, $detail['enrolled_count']);
        $this->assertSame(1, $detail['spots_remaining']);
    }

    public function test_an_unlimited_workshop_reports_no_seat_limit_rather_than_zero_left(): void
    {
        $workshop = Course::factory()->free()->create(['status' => 'published', 'registration_open' => true, 'capacity' => null, 'program_type' => 'workshop']);
        $this->fill($workshop, ['registered']);

        $detail = $this->getJson("/api/workshops/{$workshop->slug}")->assertOk()->json('data');

        $this->assertNull($detail['spots_remaining']);
        $this->assertFalse($detail['is_full']);
    }
}
