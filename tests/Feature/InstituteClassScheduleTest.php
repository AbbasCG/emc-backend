<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Institute class schedule. `class_groups.schedule_time` is a TIME column (the START of the class) and the admin form posts the browser's
 * `<input type="time">` value (HH:MM, or HH:MM:SS when an existing class is edited). Previously the controller only required a string, and
 * when the field was left empty (the form's default) it stored the TEXT '18:00 - 20:00', which a strict MariaDB rejects: HTTP 500.
 * Now: a valid time is stored as sent, an empty one falls back to 18:00, anything else is a 422 with the field named.
 */
class InstituteClassScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'admin', 'is_system' => false]);

        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function payload(array $over = []): array
    {
        $teacher = Instructor::create(['user_id' => User::factory()->create(['role' => 'instructor'])->id, 'name' => 'مدرّس']);

        return array_merge([
            'name' => 'فصل مساء', 'instructor_id' => $teacher->id, 'capacity' => 20,
            'course_ids' => [Course::factory()->free()->create()->id],
        ], $over);
    }

    private function create(array $over = [])
    {
        return $this->actingAs($this->admin(), 'sanctum')->postJson('/api/institute/admin/classes', $this->payload($over));
    }

    public function test_a_valid_start_time_is_stored_exactly_as_sent_without_any_timezone_shift(): void
    {
        config(['app.timezone' => 'America/Los_Angeles']);

        $id = $this->create(['schedule_day' => 'Sunday', 'schedule_time' => '09:30'])->assertOk()->json('class.id');

        $class = ClassGroup::findOrFail($id);
        $this->assertStringStartsWith('09:30', (string) $class->getRawOriginal('schedule_time'), 'a wall-clock time: no conversion to or from the app timezone');
        $this->assertSame('Sunday', $class->schedule_day);
    }

    public function test_the_time_with_seconds_that_an_existing_class_returns_is_accepted_on_edit(): void
    {
        $id = $this->create(['schedule_time' => '18:00'])->assertOk()->json('class.id');

        $this->actingAs($this->admin(), 'sanctum')->putJson("/api/institute/admin/classes/{$id}", ['schedule_time' => '18:00:00'])->assertOk();
        $this->assertStringStartsWith('18:00', (string) ClassGroup::findOrFail($id)->getRawOriginal('schedule_time'));
    }

    public function test_leaving_the_time_empty_or_out_gives_the_default_evening_start_instead_of_failing(): void
    {
        foreach ([[], ['schedule_time' => ''], ['schedule_time' => null]] as $over) {
            $id = $this->create($over)->assertOk()->json('class.id');
            $class = ClassGroup::findOrFail($id);

            // exactly a time (what a TIME column accepts), not the old text '18:00 - 20:00' that merely STARTS with 18:00
            $this->assertMatchesRegularExpression('/^18:00(:00)?$/', (string) $class->getRawOriginal('schedule_time'));
            $this->assertSame('Mon-Wed-Fri', $class->schedule_day);
        }
    }

    public function test_a_value_that_is_not_a_time_is_a_422_naming_the_field_and_creates_nothing(): void
    {
        foreach (['18:00 - 20:00', '25:00', '9am', '18:60', 'abc', '18'] as $bad) {
            $this->create(['schedule_time' => $bad])->assertStatus(422)->assertJsonValidationErrors('schedule_time');
        }
        $this->assertSame(0, ClassGroup::count());

        $this->create(['schedule_day' => str_repeat('x', 21)])->assertStatus(422)->assertJsonValidationErrors('schedule_day');
        $this->assertSame(0, ClassGroup::count());
    }

    public function test_an_invalid_time_on_edit_is_refused_and_leaves_the_existing_schedule_untouched(): void
    {
        $id = $this->create(['schedule_time' => '07:15', 'schedule_day' => 'Monday'])->assertOk()->json('class.id');

        $this->actingAs($this->admin(), 'sanctum')->putJson("/api/institute/admin/classes/{$id}", ['schedule_time' => '18:00 - 20:00'])
            ->assertStatus(422)->assertJsonValidationErrors('schedule_time');

        $class = ClassGroup::findOrFail($id);
        $this->assertStringStartsWith('07:15', (string) $class->getRawOriginal('schedule_time'));
        $this->assertSame('Monday', $class->schedule_day);
    }

    public function test_editing_something_else_keeps_an_existing_schedule_and_clearing_the_time_is_allowed(): void
    {
        $id = $this->create(['schedule_time' => '10:45', 'schedule_day' => 'Tuesday'])->assertOk()->json('class.id');

        $this->actingAs($this->admin(), 'sanctum')->putJson("/api/institute/admin/classes/{$id}", ['name' => 'اسم جديد'])->assertOk();
        $class = ClassGroup::findOrFail($id);
        $this->assertSame('اسم جديد', $class->name);
        $this->assertStringStartsWith('10:45', (string) $class->getRawOriginal('schedule_time'));
        $this->assertSame('Tuesday', $class->schedule_day);

        $this->actingAs($this->admin(), 'sanctum')->putJson("/api/institute/admin/classes/{$id}", ['schedule_time' => null])->assertOk();
        $this->assertNull(ClassGroup::findOrFail($id)->getRawOriginal('schedule_time'));
    }

    public function test_the_class_can_be_read_back_with_its_schedule(): void
    {
        $id = $this->create(['schedule_time' => '14:05'])->assertOk()->json('class.id');

        $body = $this->actingAs($this->admin(), 'sanctum')->getJson("/api/institute/admin/classes/{$id}")->assertOk()->json('class');
        $this->assertStringStartsWith('14:05', (string) $body['schedule_time']);
    }
}
