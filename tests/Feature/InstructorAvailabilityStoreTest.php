<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Instructor;
use App\Models\OralAssessmentSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/instructor/availability — frontend/backend contract regression.
 *
 * Root cause of the reported 422: the frontend sent `course_id: null` when
 * the instructor chose "apply to all my courses" but never sent
 * `apply_to_all_courses: true`. The backend's `course_id` validation only
 * relaxes to `nullable` when that flag is explicitly true
 * (`$request->boolean('apply_to_all_courses', false)`) — with the flag
 * absent, `course_id` stayed `required`, and `null` failed it. This suite
 * proves the backend's real contract and that the frontend fix (sending the
 * flag) actually satisfies it, plus that multiple time-window requests
 * (the frontend's new multi-period support, one POST per window) create
 * distinct, non-duplicated slots.
 */
class InstructorAvailabilityStoreTest extends TestCase
{
    use RefreshDatabase;

    private function instructor(): array
    {
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $instructor = Instructor::create(['user_id' => $user->id, 'name' => $user->name, 'title' => 'Instructor']);

        return [$user, $instructor];
    }

    public function test_apply_to_all_courses_without_the_flag_returns_422_the_originally_reported_bug(): void
    {
        [$user] = $this->instructor();

        $this->actingAs($user, 'sanctum')->postJson('/api/instructor/availability', [
            'course_id'     => null,
            'date_from'     => now()->addDay()->toDateString(),
            'date_to'       => now()->addDay()->toDateString(),
            'weekdays'      => [now()->addDay()->format('l')],
            'start_time'    => '09:00',
            'end_time'      => '12:00',
            'slot_duration' => 60,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['course_id']);
    }

    public function test_apply_to_all_courses_with_the_flag_succeeds_and_creates_slots_for_every_owned_course(): void
    {
        [$user, $instructor] = $this->instructor();
        $courseA = Course::factory()->create(['instructor_id' => $instructor->id]);
        $courseB = Course::factory()->create(['instructor_id' => $instructor->id]);

        $date = now()->addDay();
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/instructor/availability', [
            'course_id'            => null,
            'apply_to_all_courses' => true,
            'date_from'             => $date->toDateString(),
            'date_to'               => $date->toDateString(),
            'weekdays'              => [strtolower($date->format('l'))],
            'start_time'            => '09:00',
            'end_time'              => '10:00',
            'slot_duration'         => 60,
        ]);

        $response->assertCreated()->assertJson(['success' => true]);
        $this->assertSame(2, $response->json('data.created_count'));
        $this->assertDatabaseHas('oral_assessment_slots', ['instructor_id' => $instructor->id, 'course_id' => $courseA->id]);
        $this->assertDatabaseHas('oral_assessment_slots', ['instructor_id' => $instructor->id, 'course_id' => $courseB->id]);
    }

    public function test_a_specific_course_id_still_works_without_the_apply_to_all_flag(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = Course::factory()->create(['instructor_id' => $instructor->id]);
        $date = now()->addDay();

        $this->actingAs($user, 'sanctum')->postJson('/api/instructor/availability', [
            'course_id'     => $course->id,
            'date_from'     => $date->toDateString(),
            'date_to'       => $date->toDateString(),
            'weekdays'      => [strtolower($date->format('l'))],
            'start_time'    => '09:00',
            'end_time'      => '10:00',
            'slot_duration' => 60,
        ])->assertCreated();

        $this->assertDatabaseHas('oral_assessment_slots', ['instructor_id' => $instructor->id, 'course_id' => $course->id]);
    }

    public function test_multiple_time_windows_sent_as_separate_requests_create_distinct_non_duplicated_slots(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = Course::factory()->create(['instructor_id' => $instructor->id]);
        $date = now()->addDay();

        $base = [
            'course_id'     => $course->id,
            'date_from'     => $date->toDateString(),
            'date_to'       => $date->toDateString(),
            'weekdays'      => [strtolower($date->format('l'))],
            'slot_duration' => 60,
        ];

        // Two time periods in the same "session" — mirrors the frontend
        // issuing one create call per selected window.
        $morning = $this->actingAs($user, 'sanctum')->postJson('/api/instructor/availability', [
            ...$base, 'start_time' => '09:00', 'end_time' => '12:00',
        ])->assertCreated();
        $evening = $this->actingAs($user, 'sanctum')->postJson('/api/instructor/availability', [
            ...$base, 'start_time' => '18:00', 'end_time' => '21:00',
        ])->assertCreated();

        $this->assertSame(3, $morning->json('data.created_count'));
        $this->assertSame(3, $evening->json('data.created_count'));
        $this->assertSame(
            6,
            OralAssessmentSlot::where('instructor_id', $instructor->id)->where('course_id', $course->id)->count(),
        );
    }

    public function test_repeating_the_same_window_request_skips_exact_duplicates(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = Course::factory()->create(['instructor_id' => $instructor->id]);
        $date = now()->addDay();
        $payload = [
            'course_id'     => $course->id,
            'date_from'     => $date->toDateString(),
            'date_to'       => $date->toDateString(),
            'weekdays'      => [strtolower($date->format('l'))],
            'start_time'    => '09:00',
            'end_time'      => '10:00',
            'slot_duration' => 60,
        ];

        $this->actingAs($user, 'sanctum')->postJson('/api/instructor/availability', $payload)->assertCreated();
        $second = $this->actingAs($user, 'sanctum')->postJson('/api/instructor/availability', $payload)->assertCreated();

        $this->assertSame(0, $second->json('data.created_count'));
        $this->assertSame(1, $second->json('data.skipped_count'));
        $this->assertSame(1, OralAssessmentSlot::where('instructor_id', $instructor->id)->count());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/instructor/availability', [
            'course_id' => 1, 'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(),
            'weekdays' => ['sunday'], 'start_time' => '09:00', 'end_time' => '10:00', 'slot_duration' => 60,
        ])->assertUnauthorized();
    }

    public function test_a_course_owned_by_a_different_instructor_is_not_included_in_apply_to_all(): void
    {
        [$user, $instructor] = $this->instructor();
        [, $otherInstructor] = $this->instructor();
        $ownCourse   = Course::factory()->create(['instructor_id' => $instructor->id]);
        $otherCourse = Course::factory()->create(['instructor_id' => $otherInstructor->id]);
        $date = now()->addDay();

        $this->actingAs($user, 'sanctum')->postJson('/api/instructor/availability', [
            'course_id' => null, 'apply_to_all_courses' => true,
            'date_from' => $date->toDateString(), 'date_to' => $date->toDateString(),
            'weekdays'  => [strtolower($date->format('l'))],
            'start_time' => '09:00', 'end_time' => '10:00', 'slot_duration' => 60,
        ])->assertCreated();

        $this->assertDatabaseHas('oral_assessment_slots', ['instructor_id' => $instructor->id, 'course_id' => $ownCourse->id]);
        $this->assertDatabaseMissing('oral_assessment_slots', ['instructor_id' => $instructor->id, 'course_id' => $otherCourse->id]);
    }
}
