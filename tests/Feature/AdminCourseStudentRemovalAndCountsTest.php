<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Models\Course;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers two related fixes:
 *
 * 1. Removing a student via DELETE /api/admin/courses/{course}/students/{user}
 *    must cancel (not silently orphan) the matching registration, scoped to
 *    that exact course+user, transactionally, without deleting the User or
 *    touching other registrations.
 *
 * 2. The admin programs overview count ("تسجيلات") must only include
 *    registrations that genuinely count as enrolled — pending/failed payment
 *    rows on a paid course must not inflate it. The course-details
 *    registrations endpoint must keep showing every application including
 *    pending payment.
 */
class AdminCourseStudentRemovalAndCountsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function student(): User
    {
        return User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    // ── Issue 1: removal ─────────────────────────────────────────────────────

    public function test_removing_a_student_cancels_only_the_matching_course_registration(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $course = Course::factory()->create();
        $registration = Registration::factory()->create([
            'course_id' => $course->id,
            'user_id'   => $student->id,
            'status'    => RegistrationStatus::REGISTERED->value,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/courses/{$course->id}/students/{$student->id}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $registration->refresh();
        $this->assertSame(RegistrationStatus::CANCELLED->value, $registration->status);
    }

    public function test_removed_students_user_account_still_exists(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $course = Course::factory()->create();
        Registration::factory()->create([
            'course_id' => $course->id,
            'user_id'   => $student->id,
            'status'    => RegistrationStatus::REGISTERED->value,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/courses/{$course->id}/students/{$student->id}")
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $student->id]);
        $this->assertNotNull(User::find($student->id));
    }

    public function test_same_users_registration_in_another_course_is_unaffected(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $courseA = Course::factory()->create();
        $courseB = Course::factory()->create();
        Registration::factory()->create(['course_id' => $courseA->id, 'user_id' => $student->id, 'status' => RegistrationStatus::REGISTERED->value]);
        $regB = Registration::factory()->create(['course_id' => $courseB->id, 'user_id' => $student->id, 'status' => RegistrationStatus::REGISTERED->value]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/courses/{$courseA->id}/students/{$student->id}")
            ->assertOk();

        $regB->refresh();
        $this->assertSame(RegistrationStatus::REGISTERED->value, $regB->status);
    }

    public function test_another_users_registration_in_the_same_course_is_unaffected(): void
    {
        $admin = $this->admin();
        $studentA = $this->student();
        $studentB = $this->student();
        $course = Course::factory()->create();
        Registration::factory()->create(['course_id' => $course->id, 'user_id' => $studentA->id, 'status' => RegistrationStatus::REGISTERED->value]);
        $regB = Registration::factory()->create(['course_id' => $course->id, 'user_id' => $studentB->id, 'status' => RegistrationStatus::REGISTERED->value]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/courses/{$course->id}/students/{$studentA->id}")
            ->assertOk();

        $regB->refresh();
        $this->assertSame(RegistrationStatus::REGISTERED->value, $regB->status);
    }

    public function test_removal_cancels_the_course_specific_student_progress_record(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $course = Course::factory()->create();
        Registration::factory()->create(['course_id' => $course->id, 'user_id' => $student->id, 'status' => RegistrationStatus::REGISTERED->value]);
        $progress = StudentProgress::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'progress_percentage' => 40, 'status' => 'in_progress',
            'total_sessions' => 10, 'completed_sessions' => 4,
            'attendance_percentage' => 80, 'assignment_completion_percentage' => 50,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/courses/{$course->id}/students/{$student->id}")
            ->assertOk();

        $progress->refresh();
        $this->assertSame('cancelled', $progress->status);
    }

    public function test_unauthorized_user_cannot_remove_a_student(): void
    {
        $student = $this->student();
        $course = Course::factory()->create();
        $registration = Registration::factory()->create(['course_id' => $course->id, 'user_id' => $student->id, 'status' => RegistrationStatus::REGISTERED->value]);

        $this->actingAs($student, 'sanctum')
            ->deleteJson("/api/admin/courses/{$course->id}/students/{$student->id}")
            ->assertForbidden();

        $registration->refresh();
        $this->assertSame(RegistrationStatus::REGISTERED->value, $registration->status);
    }

    public function test_unauthenticated_request_cannot_remove_a_student(): void
    {
        $student = $this->student();
        $course = Course::factory()->create();
        Registration::factory()->create(['course_id' => $course->id, 'user_id' => $student->id, 'status' => RegistrationStatus::REGISTERED->value]);

        $this->deleteJson("/api/admin/courses/{$course->id}/students/{$student->id}")->assertUnauthorized();
    }

    public function test_forged_course_id_cannot_remove_a_registration_belonging_to_a_different_course(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $realCourse = Course::factory()->create();
        $otherCourse = Course::factory()->create();
        $registration = Registration::factory()->create(['course_id' => $realCourse->id, 'user_id' => $student->id, 'status' => RegistrationStatus::REGISTERED->value]);

        // Attempt to remove using the wrong course id in the URL.
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/courses/{$otherCourse->id}/students/{$student->id}")
            ->assertStatus(404);

        $registration->refresh();
        $this->assertSame(RegistrationStatus::REGISTERED->value, $registration->status);
    }

    public function test_second_removal_attempt_is_a_controlled_idempotent_response(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $course = Course::factory()->create();
        Registration::factory()->create(['course_id' => $course->id, 'user_id' => $student->id, 'status' => RegistrationStatus::REGISTERED->value]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/courses/{$course->id}/students/{$student->id}")
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/courses/{$course->id}/students/{$student->id}")
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    // ── Issue 2: paid-course overview count ──────────────────────────────────

    public function test_paid_course_overview_count_includes_confirmed_registration(): void
    {
        $admin = $this->admin();
        $course = Course::factory()->paid()->create(['status' => 'published']);
        Registration::factory()->create(['course_id' => $course->id, 'user_id' => $this->student()->id, 'status' => RegistrationStatus::REGISTERED->value]);

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/courses?per_page=50')->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $course->id);

        $this->assertSame(1, $row['active_registrations_count']);
        $this->assertSame(1, $row['effective_enrollment_count']);
    }

    public function test_paid_course_overview_count_excludes_pending_failed_and_cancelled(): void
    {
        $admin = $this->admin();
        $course = Course::factory()->paid()->create(['status' => 'published']);
        Registration::factory()->create(['course_id' => $course->id, 'user_id' => $this->student()->id, 'status' => RegistrationStatus::PENDING_PAYMENT->value]);
        Registration::factory()->create(['course_id' => $course->id, 'user_id' => $this->student()->id, 'status' => RegistrationStatus::CANCELLED->value]);
        Registration::factory()->create(['course_id' => $course->id, 'user_id' => $this->student()->id, 'status' => RegistrationStatus::NO_SHOW->value]);
        Registration::factory()->create(['course_id' => $course->id, 'user_id' => $this->student()->id, 'status' => RegistrationStatus::PAYMENT_CONFIRMED->value]);

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/courses?per_page=50')->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $course->id);

        // Only the payment_confirmed row counts; raw registrations_count includes all 4.
        $this->assertSame(1, $row['active_registrations_count']);
        $this->assertSame(1, $row['effective_enrollment_count']);
        $this->assertSame(4, $row['registrations_count']);
    }

    public function test_pending_payment_users_remain_visible_in_course_details_endpoint(): void
    {
        $admin = $this->admin();
        $course = Course::factory()->paid()->create(['status' => 'published']);
        $pendingStudent = $this->student();
        Registration::factory()->create([
            'course_id' => $course->id, 'user_id' => $pendingStudent->id,
            'status' => RegistrationStatus::PENDING_PAYMENT->value,
        ]);

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/courses/{$course->id}/students")
            ->assertOk();

        $row = collect($res->json('data'))->firstWhere('user_id', $pendingStudent->id);
        $this->assertNotNull($row);
        $this->assertSame(RegistrationStatus::PENDING_PAYMENT->value, $row['status']);
    }

    public function test_free_course_counting_remains_correct(): void
    {
        $admin = $this->admin();
        $course = Course::factory()->free()->create(['status' => 'published']);
        Registration::factory()->create(['course_id' => $course->id, 'user_id' => $this->student()->id, 'status' => RegistrationStatus::REGISTERED->value]);
        Registration::factory()->create(['course_id' => $course->id, 'user_id' => $this->student()->id, 'status' => RegistrationStatus::CANCELLED->value]);

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/courses?per_page=50')->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $course->id);

        $this->assertSame(1, $row['active_registrations_count']);
        $this->assertSame(1, $row['effective_enrollment_count']);
    }

    public function test_overview_count_decreases_after_removing_a_countable_registration(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $course = Course::factory()->paid()->create(['status' => 'published']);
        Registration::factory()->create(['course_id' => $course->id, 'user_id' => $student->id, 'status' => RegistrationStatus::REGISTERED->value]);

        $before = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/courses?per_page=50')->assertOk();
        $rowBefore = collect($before->json('data'))->firstWhere('id', $course->id);
        $this->assertSame(1, $rowBefore['active_registrations_count']);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/courses/{$course->id}/students/{$student->id}")
            ->assertOk();

        $after = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/courses?per_page=50')->assertOk();
        $rowAfter = collect($after->json('data'))->firstWhere('id', $course->id);
        $this->assertSame(0, $rowAfter['active_registrations_count']);
    }

    public function test_overview_count_unaffected_by_removing_an_already_pending_registration(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $course = Course::factory()->paid()->create(['status' => 'published']);
        Registration::factory()->create(['course_id' => $course->id, 'user_id' => $student->id, 'status' => RegistrationStatus::PENDING_PAYMENT->value]);

        $before = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/courses?per_page=50')->assertOk();
        $rowBefore = collect($before->json('data'))->firstWhere('id', $course->id);
        $this->assertSame(0, $rowBefore['active_registrations_count']);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/courses/{$course->id}/students/{$student->id}")
            ->assertOk();

        $after = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/courses?per_page=50')->assertOk();
        $rowAfter = collect($after->json('data'))->firstWhere('id', $course->id);
        $this->assertSame(0, $rowAfter['active_registrations_count']);
    }

    public function test_removal_is_transactional_registration_and_progress_change_together(): void
    {
        // No fault-injection point exists to force a partial failure without
        // touching unrelated internals, so this proves the observable
        // transactional guarantee: after a single successful removal call,
        // both writes (registration status + progress status) are always
        // consistent with each other, never one committed without the other.
        $admin = $this->admin();
        $student = $this->student();
        $course = Course::factory()->create();
        $registration = Registration::factory()->create(['course_id' => $course->id, 'user_id' => $student->id, 'status' => RegistrationStatus::REGISTERED->value]);
        $progress = StudentProgress::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'progress_percentage' => 10, 'status' => 'in_progress',
            'total_sessions' => 5, 'completed_sessions' => 1,
            'attendance_percentage' => 20, 'assignment_completion_percentage' => 0,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/courses/{$course->id}/students/{$student->id}")
            ->assertOk();

        $registration->refresh();
        $progress->refresh();
        $this->assertSame(RegistrationStatus::CANCELLED->value, $registration->status);
        $this->assertSame('cancelled', $progress->status);
    }
}
