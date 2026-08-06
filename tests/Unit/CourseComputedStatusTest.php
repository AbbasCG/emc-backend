<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Support\CourseComputedStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseComputedStatusTest extends TestCase
{
    use RefreshDatabase;

    private function course(array $overrides = []): Course
    {
        return Course::factory()->create(array_merge([
            'title'  => 'Test Course',
            'status' => 'published',
        ], $overrides));
    }

    public function test_course_before_start_date_is_upcoming(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 1, 10, 0, 0, 'UTC'));

        $course = $this->course(['start_date' => '2026-07-10', 'start_time' => '14:00', 'end_date' => '2026-07-20', 'end_time' => '18:00']);

        $this->assertSame('upcoming', CourseComputedStatus::lifecycleStatus($course));
        $this->assertFalse(CourseComputedStatus::isEnded($course));

        Carbon::setTestNow();
    }

    public function test_course_between_start_and_end_is_active(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 10, 0, 0, 'UTC'));

        $course = $this->course(['start_date' => '2026-07-10', 'start_time' => '14:00', 'end_date' => '2026-07-20', 'end_time' => '18:00']);

        $this->assertSame('active', CourseComputedStatus::lifecycleStatus($course));

        Carbon::setTestNow();
    }

    public function test_course_after_end_datetime_is_completed(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 25, 10, 0, 0, 'UTC'));

        $course = $this->course(['start_date' => '2026-07-10', 'start_time' => '14:00', 'end_date' => '2026-07-20', 'end_time' => '18:00']);

        $this->assertSame('completed', CourseComputedStatus::lifecycleStatus($course));
        $this->assertTrue(CourseComputedStatus::isEnded($course));
    }

    /** Course ending today AFTER the current Amsterdam time must remain active. */
    public function test_course_ending_today_after_current_time_remains_active(): void
    {
        // 2026-07-21 12:00 UTC = 14:00 Amsterdam (summer, UTC+2)
        Carbon::setTestNow(Carbon::create(2026, 7, 21, 12, 0, 0, 'UTC'));

        $course = $this->course(['start_date' => '2026-07-01', 'start_time' => '09:00', 'end_date' => '2026-07-21', 'end_time' => '20:00']);

        $this->assertSame('active', CourseComputedStatus::lifecycleStatus($course));

        Carbon::setTestNow();
    }

    /** Course ending today BEFORE the current Amsterdam time must become completed. */
    public function test_course_ending_today_before_current_time_becomes_completed(): void
    {
        // 2026-07-21 19:00 UTC = 21:00 Amsterdam (summer, UTC+2) — after the 20:00 end time.
        Carbon::setTestNow(Carbon::create(2026, 7, 21, 19, 0, 0, 'UTC'));

        $course = $this->course(['start_date' => '2026-07-01', 'start_time' => '09:00', 'end_date' => '2026-07-21', 'end_time' => '20:00']);

        $this->assertSame('completed', CourseComputedStatus::lifecycleStatus($course));

        Carbon::setTestNow();
    }

    /** Amsterdam summer (DST, UTC+2): a course ending 20:00 local is 18:00 UTC. */
    public function test_summer_amsterdam_timezone_boundary(): void
    {
        $course = $this->course(['start_date' => '2026-07-01', 'start_time' => '09:00', 'end_date' => '2026-07-21', 'end_time' => '20:00']);

        Carbon::setTestNow(Carbon::create(2026, 7, 21, 17, 59, 0, 'UTC')); // 19:59 Amsterdam — still active
        $this->assertSame('active', CourseComputedStatus::lifecycleStatus($course));

        Carbon::setTestNow(Carbon::create(2026, 7, 21, 18, 1, 0, 'UTC')); // 20:01 Amsterdam — completed
        $this->assertSame('completed', CourseComputedStatus::lifecycleStatus($course));

        Carbon::setTestNow();
    }

    /** Amsterdam winter (no DST, UTC+1): a course ending 20:00 local is 19:00 UTC. */
    public function test_winter_amsterdam_timezone_boundary(): void
    {
        $course = $this->course(['start_date' => '2026-01-01', 'start_time' => '09:00', 'end_date' => '2026-01-15', 'end_time' => '20:00']);

        Carbon::setTestNow(Carbon::create(2026, 1, 15, 18, 59, 0, 'UTC')); // 19:59 Amsterdam — still active
        $this->assertSame('active', CourseComputedStatus::lifecycleStatus($course));

        Carbon::setTestNow(Carbon::create(2026, 1, 15, 19, 1, 0, 'UTC')); // 20:01 Amsterdam — completed
        $this->assertSame('completed', CourseComputedStatus::lifecycleStatus($course));

        Carbon::setTestNow();
    }

    public function test_cancelled_overrides_date_logic(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 10, 0, 0, 'UTC'));

        // Course is well within its active window, but explicitly cancelled.
        $course = $this->course(['status' => 'cancelled', 'start_date' => '2026-05-01', 'end_date' => '2026-12-01']);

        $this->assertSame('cancelled', CourseComputedStatus::lifecycleStatus($course));

        Carbon::setTestNow();
    }

    public function test_archived_overrides_date_logic(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 10, 0, 0, 'UTC'));

        $course = $this->course(['status' => 'archived', 'start_date' => '2026-05-01', 'end_date' => '2026-12-01']);

        $this->assertSame('archived', CourseComputedStatus::lifecycleStatus($course));

        Carbon::setTestNow();
    }

    public function test_course_with_no_end_date_is_not_automatically_completed(): void
    {
        Carbon::setTestNow(Carbon::create(2030, 1, 1, 10, 0, 0, 'UTC'));

        $course = $this->course(['start_date' => '2026-01-01', 'end_date' => null]);

        $this->assertFalse(CourseComputedStatus::isEnded($course));
        $this->assertNotSame('completed', CourseComputedStatus::lifecycleStatus($course));

        Carbon::setTestNow();
    }
}
