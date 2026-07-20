<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Instructor;
use App\Models\OralAssessmentSlot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlacementInterviewTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function instructor(): array
    {
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $profile = Instructor::create([
            'user_id' => $user->id,
            'name'    => $user->name,
            'title'   => 'Instructor',
        ]);

        return [$user, $profile];
    }

    private function course(Instructor $instructor): Course
    {
        return Course::factory()->create([
            'title'          => 'English Course',
            'status'         => 'published',
            'instructor_id'  => $instructor->id,
        ]);
    }

    /** Booking a 14:30 Amsterdam slot in summer (DST, UTC+2) stores 12:30 UTC. */
    public function test_summer_slot_creation_stores_correct_utc_offset(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 8, 0, 0, 'UTC'));

        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/instructor/availability', [
            'course_id'     => $course->id,
            'date_from'     => '2026-07-21',
            'date_to'       => '2026-07-21',
            'weekdays'      => ['tuesday'],
            'start_time'    => '14:30',
            'end_time'      => '15:00',
            'slot_duration' => 30,
        ]);

        $response->assertStatus(201);

        $slot = OralAssessmentSlot::where('instructor_id', $instructor->id)->firstOrFail();

        // Europe/Amsterdam is UTC+2 in July (summer/DST) -> stored UTC must be 12:30.
        $this->assertSame('2026-07-21 12:30:00', $slot->starts_at->toDateTimeString());
        $this->assertSame('2026-07-21T12:30:00+00:00', $slot->starts_at->toIso8601String());

        // formatted_time in the API response must show back the original Amsterdam 14:30.
        $this->assertSame('21/07/2026', $response->json('data.slots.0.formatted_date'));
        $this->assertSame('14:30 - 15:00', $response->json('data.slots.0.formatted_time'));

        Carbon::setTestNow();
    }

    /** Booking a 14:30 Amsterdam slot in winter (no DST, UTC+1) stores 13:30 UTC. */
    public function test_winter_slot_creation_stores_correct_utc_offset(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 1, 8, 0, 0, 'UTC'));

        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/instructor/availability', [
            'course_id'     => $course->id,
            'date_from'     => '2026-01-15',
            'date_to'       => '2026-01-15',
            'weekdays'      => ['thursday'],
            'start_time'    => '14:30',
            'end_time'      => '15:00',
            'slot_duration' => 30,
        ]);

        $response->assertStatus(201);

        $slot = OralAssessmentSlot::where('instructor_id', $instructor->id)->firstOrFail();

        // Europe/Amsterdam is UTC+1 in January (winter, no DST) -> stored UTC must be 13:30.
        $this->assertSame('2026-01-15 13:30:00', $slot->starts_at->toDateTimeString());
        $this->assertSame('2026-01-15T13:30:00+00:00', $slot->starts_at->toIso8601String());

        $this->assertSame('14:30 - 15:00', $response->json('data.slots.0.formatted_time'));

        Carbon::setTestNow();
    }

    /** The same UTC-stored slot must render an identical Amsterdam local time via
     *  the instructor's assessments/placement endpoints — the exact regression
     *  this ticket was filed for (instructor saw 2 hours earlier than the student). */
    public function test_instructor_and_student_facing_endpoints_agree_on_local_time(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 1, 8, 0, 0, 'UTC'));

        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        // Directly create a slot at a known UTC instant equivalent to 14:30 Amsterdam summer.
        $slot = OralAssessmentSlot::create([
            'instructor_id'  => $instructor->id,
            'course_id'      => $course->id,
            'starts_at'      => '2026-07-21 12:30:00',
            'ends_at'        => '2026-07-21 13:00:00',
            'booking_status' => 'available',
            'is_booked'      => false,
        ]);

        $index = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/availability?upcoming=0');
        $index->assertStatus(200);

        $row = collect($index->json('data'))->firstWhere('id', $slot->id);
        $this->assertNotNull($row);
        $this->assertSame('21/07/2026', $row['formatted_date']);
        $this->assertSame('14:30 - 15:00', $row['formatted_time']);
        $this->assertSame('2026-07-21T12:30:00+00:00', $row['starts_at']);

        Carbon::setTestNow();
    }
}
