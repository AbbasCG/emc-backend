<?php

namespace Tests\Feature;

use App\Http\Resources\CourseSessionResource;
use App\Models\Course;
use App\Models\CourseSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Admin LMS session card +2-hour display bug (graphify root-cause pass).
 *
 * Root cause: CourseSessionResource::toArray() derived session_date/
 * start_time/end_time by calling ->setTimezone('Europe/Amsterdam') on
 * start_at/end_at. AdminLmsSessionController::store()/update() build
 * start_at/end_at as a naive "{date} {time}:00" string with no real
 * timezone conversion (app.timezone is UTC), so Carbon's own H:i components
 * already ARE the entered wall-clock time — calling ->setTimezone()
 * re-interpreted that naive value as a UTC instant and shifted it by the
 * Europe/Amsterdam DST offset, corrupting the API response itself, before
 * the frontend ever received it. This is why a purely frontend formatter
 * fix (swapping formatLmsTime for formatWallClockTime24) had no effect.
 */
class AdminLmsSessionWallClockTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function courseSession(string $startAt, string $endAt): CourseSession
    {
        $course = Course::factory()->create();

        return CourseSession::query()->create([
            'course_id' => $course->id,
            'title'     => 'Test Session',
            'start_at'  => $startAt,
            'end_at'    => $endAt,
            'status'    => 'scheduled',
        ]);
    }

    // ── Resource-level (direct, no HTTP) ─────────────────────────────────────

    public function test_resource_preserves_wall_clock_start_and_end_time(): void
    {
        $session = $this->courseSession('2026-07-24 20:00:00', '2026-07-24 21:00:00');

        $arr = (new CourseSessionResource($session))->toArray(Request::create('/'));

        $this->assertSame('2026-07-24', $arr['session_date']);
        $this->assertSame('20:00', $arr['start_time']);
        $this->assertSame('21:00', $arr['end_time']);
    }

    public function test_resource_handles_the_originally_reported_20_to_22_example(): void
    {
        $session = $this->courseSession('2026-07-24 20:00:00', '2026-07-24 22:00:00');

        $arr = (new CourseSessionResource($session))->toArray(Request::create('/'));

        $this->assertSame('20:00', $arr['start_time']);
        $this->assertSame('22:00', $arr['end_time']);
    }

    public function test_resource_midnight_end_time_does_not_roll_to_next_day(): void
    {
        $session = $this->courseSession('2026-07-24 22:00:00', '2026-07-25 00:00:00');

        $arr = (new CourseSessionResource($session))->toArray(Request::create('/'));

        $this->assertSame('22:00', $arr['start_time']);
        $this->assertSame('00:00', $arr['end_time']);
    }

    public function test_resource_start_time_is_correct_across_winter_non_dst_dates_too(): void
    {
        $session = $this->courseSession('2026-01-15 09:30:00', '2026-01-15 11:00:00');

        $arr = (new CourseSessionResource($session))->toArray(Request::create('/'));

        $this->assertSame('09:30', $arr['start_time']);
        $this->assertSame('11:00', $arr['end_time']);
    }

    public function test_real_utc_instant_fields_are_unaffected_by_the_fix(): void
    {
        $session = $this->courseSession('2026-07-24 20:00:00', '2026-07-24 21:00:00');

        $arr = (new CourseSessionResource($session))->toArray(Request::create('/'));

        // starts_at/ends_at/start_at/end_at remain real ISO instants, untouched.
        $this->assertSame('2026-07-24T20:00:00.000000Z', $arr['start_at']);
        $this->assertSame('2026-07-24T20:00:00.000000Z', $arr['starts_at']);
        $this->assertSame('2026-07-24T21:00:00.000000Z', $arr['end_at']);
    }

    // ── Full HTTP round trip through the actual admin endpoints ─────────────

    public function test_create_then_list_returns_the_entered_wall_clock_time_not_shifted(): void
    {
        $admin = $this->admin();
        $course = Course::factory()->create();

        $create = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/lms/sessions', [
            'course_id'    => $course->id,
            'title'        => 'Graphify Regression Session',
            'session_date' => '2026-07-24',
            'start_time'   => '20:00',
            'end_time'     => '21:00',
        ])->assertCreated();

        $sessionId = $create->json('data.id');
        $this->assertNotNull($sessionId);

        // Database is unaffected — literal naive datetime, no shift.
        $this->assertDatabaseHas('course_sessions', [
            'id'       => $sessionId,
            'start_at' => '2026-07-24 20:00:00',
            'end_at'   => '2026-07-24 21:00:00',
        ]);

        // The list endpoint (what the session card actually renders from)
        // must return the same wall-clock values the admin entered.
        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/lms/sessions')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $sessionId);

        $this->assertNotNull($row);
        $this->assertSame('2026-07-24', $row['session_date']);
        $this->assertSame('20:00', $row['start_time']);
        $this->assertSame('21:00', $row['end_time']);
    }

    public function test_update_then_show_returns_the_entered_wall_clock_time_not_shifted(): void
    {
        $admin = $this->admin();
        $session = $this->courseSession('2026-07-01 10:00:00', '2026-07-01 11:00:00');

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/lms/sessions/{$session->id}", [
            'session_date' => '2026-07-24',
            'start_time'   => '20:00',
            'end_time'     => '21:00',
        ])->assertOk();

        $show = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/lms/sessions/{$session->id}")->assertOk();

        $this->assertSame('20:00', $show->json('data.start_time'));
        $this->assertSame('21:00', $show->json('data.end_time'));
    }
}
