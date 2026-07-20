<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassGroupScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function instructor(): array
    {
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $profile = Instructor::create(['user_id' => $user->id, 'name' => $user->name, 'title' => 'Instructor']);

        return [$user, $profile];
    }

    private function course(Instructor $instructor): Course
    {
        return Course::factory()->create([
            'title' => 'English Course', 'status' => 'published', 'instructor_id' => $instructor->id,
        ]);
    }

    public function test_class_can_be_created_with_a_single_schedule_row(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/instructor/classes', [
            'course_id' => $course->id,
            'name'      => 'A1 Class',
            'schedules' => [
                ['day_of_week' => 'monday', 'start_time' => '10:00', 'end_time' => '11:30', 'delivery_mode' => 'online'],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonCount(1, 'data.schedules');
        $response->assertJsonPath('data.schedules.0.day_of_week', 'monday');
        $response->assertJsonPath('data.schedules.0.start_time', '10:00');
        $response->assertJsonPath('data.schedules.0.end_time', '11:30');
    }

    public function test_class_can_be_created_with_multiple_schedule_rows(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/instructor/classes', [
            'course_id' => $course->id,
            'name'      => 'A1 Class',
            'schedules' => [
                ['day_of_week' => 'sunday',    'start_time' => '09:00', 'end_time' => '10:30'],
                ['day_of_week' => 'monday',    'start_time' => '09:00', 'end_time' => '10:30'],
                ['day_of_week' => 'tuesday',   'start_time' => '09:00', 'end_time' => '10:30'],
                ['day_of_week' => 'wednesday', 'start_time' => '14:00', 'end_time' => '15:30'],
                ['day_of_week' => 'thursday',  'start_time' => '14:00', 'end_time' => '15:30'],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonCount(5, 'data.schedules');
    }

    public function test_duplicate_day_and_start_time_is_rejected(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/instructor/classes', [
            'course_id' => $course->id,
            'name'      => 'A1 Class',
            'schedules' => [
                ['day_of_week' => 'monday', 'start_time' => '10:00', 'end_time' => '11:00'],
                ['day_of_week' => 'monday', 'start_time' => '10:00', 'end_time' => '12:00'],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_end_time_before_start_time_is_rejected(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/instructor/classes', [
            'course_id' => $course->id,
            'name'      => 'A1 Class',
            'schedules' => [
                ['day_of_week' => 'monday', 'start_time' => '11:00', 'end_time' => '10:00'],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_updating_schedules_replaces_previous_rows(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group  = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'name' => 'A1 Class', 'capacity' => 20, 'status' => 'draft',
        ]);
        $group->schedules()->create([
            'day_of_week' => 'sunday', 'start_time' => '08:00', 'end_time' => '09:00', 'delivery_mode' => 'online',
        ]);

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/instructor/classes/{$group->id}", [
            'schedules' => [
                ['day_of_week' => 'wednesday', 'start_time' => '13:00', 'end_time' => '14:30'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data.schedules');
        $response->assertJsonPath('data.schedules.0.day_of_week', 'wednesday');
        $this->assertDatabaseCount('class_group_schedules', 1);
    }

    public function test_class_group_id_route_scoping_prevents_editing_another_instructors_class(): void
    {
        [$userA, $instructorA] = $this->instructor();
        [, $instructorB] = $this->instructor();
        $courseA = $this->course($instructorA);
        $group   = ClassGroup::create([
            'course_id' => $courseA->id, 'instructor_id' => $instructorB->id,
            'name' => 'B1 Class', 'capacity' => 20, 'status' => 'draft',
        ]);

        $response = $this->actingAs($userA, 'sanctum')->patchJson("/api/instructor/classes/{$group->id}", [
            'schedules' => [
                ['day_of_week' => 'monday', 'start_time' => '10:00', 'end_time' => '11:00'],
            ],
        ]);

        $response->assertStatus(403);
    }
}
