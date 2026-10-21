<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Instructor;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\ManualPayment;
use App\Models\FinanceAccount;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\FakesStripe;
use Tests\TestCase;

/**
 * The write paths that used to put an enrolled learner in front of their own program WITHOUT passing the model-level guard:
 * the Institute class group (created with its instructor BEFORE its courses exist, and its courses synced AFTER the row is
 * written), learning-path membership changes, attaching a course to a path, and the writers that grant learner access
 * (manual payment confirmation of an existing pending registration, free learning-path enrollment, admin manual enrollment).
 *
 * Also pins the lock order that the real-MariaDB race checks proved necessary: the course rows are the FIRST thing a
 * transaction that grants learner access takes (a plain read before them fixes the REPEATABLE READ snapshot ahead of the lock).
 */
class InstructorAssignmentBypassTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
        $this->fakeStripe();
    }

    protected function tearDown(): void
    {
        $this->restoreStripe();
        parent::tearDown();
    }

    private function user(string $role = 'volunteer'): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function profile(User $u): Instructor
    {
        return Instructor::firstOrCreate(['user_id' => $u->id], ['name' => $u->name]);
    }

    private function course(array $a = []): Course
    {
        return Course::factory()->free()->create(array_merge(['registration_open' => true, 'status' => 'published'], $a));
    }

    private function learnerIn(Course $course, string $status = 'registered'): User
    {
        $u = $this->user('hr_manager');
        Registration::factory()->create(['user_id' => $u->id, 'course_id' => $course->id, 'status' => $status]);

        return $u;
    }

    private function path(array $a = []): LearningPath
    {
        return LearningPath::create(array_merge([
            'title' => 'مسار', 'slug' => 'p-'.uniqid(), 'status' => 'published', 'enrollment_open' => true, 'price' => 0, 'currency' => 'EUR', 'duration' => 1,
            'duration_unit' => 'weeks', 'language' => 'ar', 'level' => 'beginner', 'short_description' => 'x', 'full_description' => 'x',
        ], $a));
    }

    // ── Institute class groups ───────────────────────────────────────────────

    public function test_an_institute_class_cannot_be_created_with_an_instructor_who_learns_one_of_its_courses(): void
    {
        $admin = $this->user('admin');
        $c1 = $this->course();
        $c2 = $this->course();
        $learner = $this->learnerIn($c2);
        $payload = ['name' => 'g', 'capacity' => 10, 'course_ids' => [$c1->id, $c2->id], 'schedule_day' => 'Mon', 'schedule_time' => '18:00'];

        $this->actingAs($admin, 'sanctum')->postJson('/api/institute/admin/classes', $payload + ['instructor_id' => $this->profile($learner)->id])
            ->assertStatus(422)->assertJsonValidationErrors('instructor_id');
        $this->assertDatabaseCount('class_groups', 0);

        // someone who does not learn any of them is fine
        $teacher = $this->profile($this->user('instructor'));
        $this->actingAs($admin, 'sanctum')->postJson('/api/institute/admin/classes', $payload + ['instructor_id' => $teacher->id])->assertOk();
        $this->assertDatabaseCount('class_groups', 1);
    }

    public function test_adding_a_course_to_an_institute_class_is_checked_against_its_instructor(): void
    {
        $admin = $this->user('admin');
        $c1 = $this->course();
        $c2 = $this->course();
        $learner = $this->learnerIn($c2);
        $class = \App\Models\ClassGroup::create(['name' => 'g', 'level_code' => 'A1', 'capacity' => 10, 'status' => 'active', 'is_institute' => true]);
        $class->courses()->attach($c1->id);
        $class->forceFill(['instructor_id' => $this->profile($learner)->id])->saveQuietly();

        $this->actingAs($admin, 'sanctum')->putJson("/api/institute/admin/classes/{$class->id}", ['course_ids' => [$c1->id, $c2->id]])
            ->assertStatus(422)->assertJsonValidationErrors('instructor_id');
        $this->assertSame([$c1->id], $class->fresh()->courses()->pluck('courses.id')->all(), 'the pivot is untouched');

        // removing the course instead is fine, and so is a change that keeps the pairing legitimate
        $this->actingAs($admin, 'sanctum')->putJson("/api/institute/admin/classes/{$class->id}", ['course_ids' => [$c1->id]])->assertOk();
    }

    // ── learning path membership ─────────────────────────────────────────────

    public function test_a_course_cannot_be_added_to_a_path_whose_instructor_is_a_learner_of_that_course(): void
    {
        $admin = $this->user('admin');
        $inPath = $this->course();
        $other = $this->course();
        $learner = $this->learnerIn($other);
        $path = $this->path();
        $path->courses()->attach($inPath->id, ['sort_order' => 1]);
        $path->update(['instructor_id' => $this->profile($learner)->id]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/learning-paths/{$path->id}/courses", ['course_ids' => [$inPath->id, $other->id]])
            ->assertStatus(422)->assertJsonValidationErrors('instructor_id');
        $this->assertSame([$inPath->id], $path->fresh()->courses()->pluck('courses.id')->all());
        $this->assertNull($other->fresh()->instructor_id, 'the path instructor was not inherited by the course');
    }

    public function test_attaching_a_single_course_to_a_path_from_the_course_form_is_checked_too(): void
    {
        $admin = $this->user('admin');
        $course = $this->course();
        $learner = $this->learnerIn($course);
        $path = $this->path();
        $path->update(['instructor_id' => $this->profile($learner)->id]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/courses/{$course->id}", ['learning_path_id' => $path->id])->assertStatus(422);
        $this->assertDatabaseMissing('learning_path_items', ['learning_path_id' => $path->id, 'course_id' => $course->id]);
    }

    // ── writers that grant learner access ────────────────────────────────────

    public function test_a_manual_payment_cannot_activate_the_pending_registration_of_the_courses_instructor(): void
    {
        $finance = $this->user('finance_manager');
        $course = $this->course(['type' => 'paid', 'is_free' => false, 'price' => 40, 'finance_approval_status' => 'approved']);
        $payer = $this->user('hr_manager');
        $reg = Registration::factory()->create(['user_id' => $payer->id, 'course_id' => $course->id, 'status' => 'pending_payment']);
        $account = FinanceAccount::create(['name' => 'Bank', 'type' => 'bank', 'currency' => 'EUR']);
        $mp = ManualPayment::create(['student_id' => $payer->id, 'purchasable_type' => Course::class, 'purchasable_id' => $course->id, 'finance_account_id' => $account->id, 'expected_amount' => 40, 'paid_amount' => 40, 'difference_amount' => 0, 'currency' => 'EUR', 'status' => 'pending_review', 'payment_date' => now()->toDateString()]);
        $course->update(['instructor_id' => $this->profile($payer)->id]); // assigned while the payment was waiting for review

        $this->actingAs($finance, 'sanctum')->postJson("/api/finance/manual-payments/{$mp->id}/confirm")->assertStatus(403)->assertJsonPath('code', 'instructor_teaches_program');

        $this->assertSame('pending_payment', $reg->fresh()->status, 'no learner access was granted');
        $this->assertSame('pending_review', $mp->fresh()->status, 'nothing was half-confirmed');
    }

    public function test_a_free_learning_path_enrollment_is_refused_for_the_instructor_of_one_of_its_courses(): void
    {
        $course = $this->course();
        $path = $this->path();
        $path->courses()->attach($course->id, ['sort_order' => 1]);
        $teacher = $this->user('tech_admin');
        $course->update(['instructor_id' => $this->profile($teacher)->id]);

        $this->actingAs($teacher, 'sanctum')->postJson("/api/learning-paths/{$path->slug}/enroll")->assertStatus(403)->assertJsonPath('code', 'instructor_teaches_program');
        $this->assertSame(0, LearningPathEnrollment::count());
        $this->assertSame(0, Registration::where('user_id', $teacher->id)->count());
    }

    public function test_admin_manual_enrollment_is_refused_for_the_instructor(): void
    {
        $admin = $this->user('admin');
        $course = $this->course();
        $teacher = $this->user('super_admin');
        $course->update(['instructor_id' => $this->profile($teacher)->id]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/courses/{$course->id}/students", ['user_id' => $teacher->id])->assertStatus(403);
        $this->assertSame(0, Registration::where('user_id', $teacher->id)->count());
    }

    // ── lock order (the MariaDB snapshot rule) ───────────────────────────────

    /**
     * @return list<string> the SELECTs that ran after the transaction began, lowercased
     */
    private function selectsInsideTransaction(callable $act): array
    {
        $inside = false;
        $queries = [];
        Event::listen(TransactionBeginning::class, function () use (&$inside) {
            $inside = true;
        });
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$inside, &$queries) {
            if ($inside && str_starts_with(strtolower($q->sql), 'select')) {
                $queries[] = strtolower($q->sql);
            }
        });
        $act();

        return $queries;
    }

    public function test_the_writers_that_grant_access_take_the_course_locks_before_reading_anything_in_their_transaction(): void
    {
        $course = $this->course();
        $path = $this->path();
        $path->courses()->attach($course->id, ['sort_order' => 1]);

        // free learning-path enrollment
        $user = $this->user('volunteer');
        $queries = $this->selectsInsideTransaction(fn () => app(\App\Services\LearningPathEnrollmentService::class)->enrollFree($user, $path));
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('from "courses"', $queries[0], 'enrollFree: the first read inside the transaction must be the course lock');

        // admin manual enrollment
        $admin = $this->user('admin');
        $course2 = $this->course();
        $student = $this->user('partner');
        $queries = $this->selectsInsideTransaction(fn () => $this->actingAs($admin, 'sanctum')->postJson("/api/admin/courses/{$course2->id}/students", ['user_id' => $student->id])->assertCreated());
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('from "courses"', $queries[0], 'manual enrollment: the first read inside the transaction must be the course lock');
    }
}
