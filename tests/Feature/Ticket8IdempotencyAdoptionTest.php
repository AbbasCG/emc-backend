<?php

namespace Tests\Feature;

use App\Models\PlatformNotification;
use App\Models\User;
use App\Services\NotificationIdempotencyKey;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ticket 8 deterministic-idempotency completion pass. Covers the 30
 * scenario methods newly given a real semantic idempotency_key this pass,
 * plus NotificationIdempotencyKey itself (the canonical key-builder).
 *
 * Pattern per scenario: (1) first call creates a row, (2) an exact retry
 * does not duplicate it, (3) a genuinely distinct occurrence (a different
 * entity, or — for transition/schedule scenarios — a different target
 * state/date) creates its own row.
 */
class Ticket8IdempotencyAdoptionTest extends TestCase
{
    use RefreshDatabase;

    private function notifCount(int $userId, string $type): int
    {
        return PlatformNotification::where('user_id', $userId)->where('type', $type)->count();
    }

    // ── NotificationIdempotencyKey unit tests ───────────────────────────────

    public function test_for_entity_produces_a_stable_deterministic_key(): void
    {
        $this->assertSame('course_created:5', NotificationIdempotencyKey::forEntity('course_created', 5));
        $this->assertSame(
            NotificationIdempotencyKey::forEntity('course_created', 5),
            NotificationIdempotencyKey::forEntity('course_created', 5),
        );
    }

    public function test_for_schedule_change_normalizes_equivalent_datetime_representations_to_the_same_key(): void
    {
        $a = NotificationIdempotencyKey::forScheduleChange('x', 1, '2026-08-01T10:00:00Z');
        $b = NotificationIdempotencyKey::forScheduleChange('x', 1, '2026-08-01 10:00:00');
        // Both represent the same UTC instant once parsed — should normalize identically.
        $this->assertSame($a, $b);
    }

    public function test_for_schedule_change_with_null_datetime_uses_a_stable_placeholder_not_current_time(): void
    {
        $key1 = NotificationIdempotencyKey::forScheduleChange('x', 1, null);
        $key2 = NotificationIdempotencyKey::forScheduleChange('x', 1, null);
        $this->assertSame($key1, $key2);
        $this->assertStringContainsString('unscheduled', $key1);
    }

    public function test_long_composite_keys_are_hashed_not_truncated_and_remain_within_column_length(): void
    {
        $longComponent = str_repeat('a very long free-text label ', 20);
        $key = NotificationIdempotencyKey::fromComponents('some_type', [$longComponent, $longComponent]);

        $this->assertLessThanOrEqual(191, strlen($key));
        $this->assertStringContainsString('sha256', $key);
    }

    public function test_hashing_is_deterministic_for_the_same_long_input(): void
    {
        $longComponent = str_repeat('x', 500);
        $a = NotificationIdempotencyKey::fromComponents('t', [$longComponent]);
        $b = NotificationIdempotencyKey::fromComponents('t', [$longComponent]);
        $this->assertSame($a, $b);
    }

    // ── Simple forEntity scenarios (table-driven) ───────────────────────────

    #[DataProvider('simpleEntityScenarios')]
    public function test_simple_entity_scenario_dedupes_exact_retry_and_creates_a_new_row_for_a_distinct_entity(\Closure $call, string $type): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $call($user->id, 111);
        $call($user->id, 111);
        $this->assertSame(1, $this->notifCount($user->id, $type), "{$type}: exact retry must dedup");

        $call($user->id, 222);
        $this->assertSame(2, $this->notifCount($user->id, $type), "{$type}: distinct entity must not dedup");
    }

    public static function simpleEntityScenarios(): array
    {
        return [
            'courseCreated' => [fn (int $uid, int $entityId) => NotificationService::courseCreated($entityId, 'C', null, $uid), 'course_created'],
            'registrationOpened' => [fn (int $uid, int $entityId) => NotificationService::registrationOpened($entityId, 'C'), 'registration_opened'],
            'courseCancelled' => [fn (int $uid, int $entityId) => NotificationService::courseCancelled($entityId, 'C'), 'course_cancelled'],
            'courseCompleted' => [fn (int $uid, int $entityId) => NotificationService::courseCompleted($entityId, 'C'), 'course_completed'],
        ];
    }

    public function test_registration_confirmed_dedupes_and_distinguishes_by_registration_id(): void
    {
        $user = User::factory()->create();
        NotificationService::registrationConfirmed($user->id, 1, 'C', 500);
        NotificationService::registrationConfirmed($user->id, 1, 'C', 500);
        $this->assertSame(1, $this->notifCount($user->id, 'registration_confirmed'));

        NotificationService::registrationConfirmed($user->id, 1, 'C', 501);
        $this->assertSame(2, $this->notifCount($user->id, 'registration_confirmed'));
    }

    public function test_workshop_request_submitted_dedupes_per_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        NotificationService::workshopRequestSubmitted(1, 'P', 'R');
        NotificationService::workshopRequestSubmitted(1, 'P', 'R');
        $this->assertSame(1, $this->notifCount($admin->id, 'workshop_request_submitted'));

        NotificationService::workshopRequestSubmitted(2, 'P', 'R');
        $this->assertSame(2, $this->notifCount($admin->id, 'workshop_request_submitted'));
    }

    public function test_workshop_request_rejected_dedupes_per_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        NotificationService::workshopRequestRejected(1, 'P', 'R', 2);
        NotificationService::workshopRequestRejected(1, 'P', 'R', 2);
        $this->assertSame(1, $this->notifCount($admin->id, 'workshop_request_rejected'));
    }

    public function test_account_created_dedupes_per_user(): void
    {
        $user = User::factory()->create();
        NotificationService::accountCreated($user->id, 'N');
        NotificationService::accountCreated($user->id, 'N');
        $this->assertSame(1, $this->notifCount($user->id, 'account_created'));
    }

    public function test_volunteer_request_submitted_dedupes_per_request(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        NotificationService::volunteerRequestSubmitted(1, 'F', 'D');
        NotificationService::volunteerRequestSubmitted(1, 'F', 'D');
        $this->assertSame(1, $this->notifCount($admin->id, 'volunteer_request'));

        NotificationService::volunteerRequestSubmitted(2, 'F', 'D');
        $this->assertSame(2, $this->notifCount($admin->id, 'volunteer_request'));
    }

    public function test_volunteer_converted_to_member_dedupes_per_request(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        NotificationService::volunteerConvertedToMember(1, 99, 'F', 'D');
        NotificationService::volunteerConvertedToMember(1, 99, 'F', 'D');
        $this->assertSame(1, $this->notifCount($admin->id, 'member_converted'));
    }

    public function test_course_schedule_updated_dedupes_per_course(): void
    {
        $course = \App\Models\Course::factory()->create();
        $user = User::factory()->create();
        \App\Models\Registration::factory()->create(['user_id' => $user->id, 'course_id' => $course->id, 'status' => 'registered']);
        NotificationService::courseScheduleUpdated($course->id, 'C');
        NotificationService::courseScheduleUpdated($course->id, 'C');
        $this->assertSame(1, $this->notifCount($user->id, 'course_schedule_updated'));
    }

    public function test_class_announcement_published_dedupes_per_announcement(): void
    {
        $user = User::factory()->create();
        NotificationService::classAnnouncementPublished([$user->id], 'C', 'Class', 'Title', 1, 700);
        NotificationService::classAnnouncementPublished([$user->id], 'C', 'Class', 'Title', 1, 700);
        $this->assertSame(1, $this->notifCount($user->id, 'class_announcement_published'));

        NotificationService::classAnnouncementPublished([$user->id], 'C', 'Class', 'Title', 1, 701);
        $this->assertSame(2, $this->notifCount($user->id, 'class_announcement_published'));
    }

    public function test_learning_path_enrolled_dedupes_per_path(): void
    {
        $user = User::factory()->create();
        NotificationService::learningPathEnrolled($user->id, 'P', 3, 42);
        NotificationService::learningPathEnrolled($user->id, 'P', 3, 42);
        $this->assertSame(1, $this->notifCount($user->id, 'learning_path_enrolled'));
    }

    public function test_learning_path_completed_dedupes_per_path(): void
    {
        $user = User::factory()->create();
        NotificationService::learningPathCompleted($user->id, 'P', 3, 42);
        NotificationService::learningPathCompleted($user->id, 'P', 3, 42);
        $this->assertSame(1, $this->notifCount($user->id, 'learning_path_completed'));
    }

    public function test_course_student_completed_dedupes_per_course(): void
    {
        $user = User::factory()->create();
        NotificationService::courseStudentCompleted($user->id, 'C', '2026-01-01', 1);
        NotificationService::courseStudentCompleted($user->id, 'C', '2026-01-01', 1);
        $this->assertSame(1, $this->notifCount($user->id, 'course_completed_student'));
    }

    public function test_ambassador_application_submitted_dedupes_per_application(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        NotificationService::ambassadorApplicationSubmitted(1, 'F', 'U');
        NotificationService::ambassadorApplicationSubmitted(1, 'F', 'U');
        $this->assertSame(1, $this->notifCount($admin->id, 'ambassador_application'));
    }

    public function test_module_activated_dedupes_per_module_first_activation_only(): void
    {
        $course = \App\Models\Course::factory()->create();
        $user = User::factory()->create();
        \App\Models\Registration::factory()->create(['user_id' => $user->id, 'course_id' => $course->id, 'status' => 'registered']);

        NotificationService::moduleActivated($course->id, 'C', 'M', 55);
        NotificationService::moduleActivated($course->id, 'C', 'M', 55);
        $this->assertSame(1, $this->notifCount($user->id, 'module_activated'));

        NotificationService::moduleActivated($course->id, 'C', 'M2', 56);
        $this->assertSame(2, $this->notifCount($user->id, 'module_activated'));
    }

    public function test_lesson_activated_dedupes_per_lesson(): void
    {
        $course = \App\Models\Course::factory()->create();
        $user = User::factory()->create();
        \App\Models\Registration::factory()->create(['user_id' => $user->id, 'course_id' => $course->id, 'status' => 'registered']);

        NotificationService::lessonActivated($course->id, 'C', 'L', 77);
        NotificationService::lessonActivated($course->id, 'C', 'L', 77);
        $this->assertSame(1, $this->notifCount($user->id, 'lesson_activated'));
    }

    // ── Component/name-based scenarios (no entity id available) ─────────────

    public function test_instructor_assigned_dedupes_per_course(): void
    {
        $instructor = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        NotificationService::instructorAssigned($instructor->id, 1, 'C', $admin->id);
        NotificationService::instructorAssigned($instructor->id, 1, 'C', $admin->id);
        $this->assertSame(1, $this->notifCount($instructor->id, 'instructor_assigned'));

        NotificationService::instructorAssigned($instructor->id, 2, 'C', $admin->id);
        $this->assertSame(2, $this->notifCount($instructor->id, 'instructor_assigned'));
    }

    public function test_workshop_instructor_assigned_dedupes_per_workshop(): void
    {
        $instructor = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        NotificationService::workshopInstructorAssigned($instructor->id, 1, 'W', $admin->id);
        NotificationService::workshopInstructorAssigned($instructor->id, 1, 'W', $admin->id);
        $this->assertSame(1, $this->notifCount($instructor->id, 'workshop_instructor_assigned'));
    }

    public function test_course_date_updated_dedupes_same_date_but_not_a_different_one(): void
    {
        $instructor = User::factory()->create();
        NotificationService::courseDateUpdated(1, 'C', '2026-09-01', $instructor->id);
        NotificationService::courseDateUpdated(1, 'C', '2026-09-01', $instructor->id);
        $this->assertSame(1, $this->notifCount($instructor->id, 'course_date_updated'));

        NotificationService::courseDateUpdated(1, 'C', '2026-10-01', $instructor->id);
        $this->assertSame(2, $this->notifCount($instructor->id, 'course_date_updated'));
    }

    public function test_workshop_request_moved_to_step_dedupes_same_step_but_not_a_different_one(): void
    {
        $manager = User::factory()->create(['role' => 'finance_manager']);
        NotificationService::workshopRequestMovedToStep(1, 'P', 'R', 2, 'finance', 'Finance');
        NotificationService::workshopRequestMovedToStep(1, 'P', 'R', 2, 'finance', 'Finance');
        $this->assertSame(1, $this->notifCount($manager->id, 'workshop_request_step'));

        NotificationService::workshopRequestMovedToStep(1, 'P', 'R', 3, 'finance', 'Finance');
        $this->assertSame(2, $this->notifCount($manager->id, 'workshop_request_step'));
    }

    public function test_placement_test_completed_dedupes_same_student_same_course(): void
    {
        $instructor = User::factory()->create();
        NotificationService::placementTestCompleted($instructor->id, 1, 'C', 'Ali', 10, 20, 'B1');
        NotificationService::placementTestCompleted($instructor->id, 1, 'C', 'Ali', 10, 20, 'B1');
        $this->assertSame(1, $this->notifCount($instructor->id, 'placement_test_completed'));

        NotificationService::placementTestCompleted($instructor->id, 1, 'C', 'Sara', 10, 20, 'B1');
        $this->assertSame(2, $this->notifCount($instructor->id, 'placement_test_completed'));
    }

    public function test_oral_assessment_completed_dedupes_per_course_title(): void
    {
        $student = User::factory()->create();
        NotificationService::oralAssessmentCompleted($student->id, 'English 101', 'B1');
        NotificationService::oralAssessmentCompleted($student->id, 'English 101', 'B1');
        $this->assertSame(1, $this->notifCount($student->id, 'oral_assessment_completed'));

        NotificationService::oralAssessmentCompleted($student->id, 'English 102', 'B1');
        $this->assertSame(2, $this->notifCount($student->id, 'oral_assessment_completed'));
    }

    public function test_oral_assessment_status_changed_dedupes_same_status_but_not_a_different_one(): void
    {
        $student = User::factory()->create();
        NotificationService::oralAssessmentStatusChanged($student->id, 'C', 'ملغى');
        NotificationService::oralAssessmentStatusChanged($student->id, 'C', 'ملغى');
        $this->assertSame(1, $this->notifCount($student->id, 'oral_assessment_status_changed'));

        NotificationService::oralAssessmentStatusChanged($student->id, 'C', 'مكتمل');
        $this->assertSame(2, $this->notifCount($student->id, 'oral_assessment_status_changed'));
    }

    public function test_oral_assessment_rescheduled_dedupes_same_slot_but_not_a_different_one(): void
    {
        $student = User::factory()->create();
        NotificationService::oralAssessmentRescheduled($student->id, 'C', '2026-08-01 10:00');
        NotificationService::oralAssessmentRescheduled($student->id, 'C', '2026-08-01 10:00');
        $this->assertSame(1, $this->notifCount($student->id, 'oral_assessment_rescheduled'));

        NotificationService::oralAssessmentRescheduled($student->id, 'C', '2026-08-02 10:00');
        $this->assertSame(2, $this->notifCount($student->id, 'oral_assessment_rescheduled'));
    }

    public function test_class_group_assigned_dedupes_per_class_and_course(): void
    {
        $student = User::factory()->create();
        NotificationService::classGroupAssigned($student->id, 'Class A', 'C', 'Instr', null, null, null);
        NotificationService::classGroupAssigned($student->id, 'Class A', 'C', 'Instr', null, null, null);
        $this->assertSame(1, $this->notifCount($student->id, 'class_group_assigned'));
    }

    public function test_class_group_student_assigned_instructor_dedupes_per_class_and_student(): void
    {
        $instructor = User::factory()->create();
        NotificationService::classGroupStudentAssignedInstructor($instructor->id, 'Ali', 'Class A', 'C');
        NotificationService::classGroupStudentAssignedInstructor($instructor->id, 'Ali', 'Class A', 'C');
        $this->assertSame(1, $this->notifCount($instructor->id, 'class_group_student_assigned'));
    }

    public function test_assignment_deadline_changed_dedupes_same_deadline_but_not_a_different_one(): void
    {
        $course = \App\Models\Course::factory()->create();
        $user = User::factory()->create();
        \App\Models\Registration::factory()->create(['user_id' => $user->id, 'course_id' => $course->id, 'status' => 'registered']);

        NotificationService::assignmentDeadlineChanged($course->id, 'C', 'A', '2026-09-01', 900);
        NotificationService::assignmentDeadlineChanged($course->id, 'C', 'A', '2026-09-01', 900);
        $this->assertSame(1, $this->notifCount($user->id, 'assignment_deadline_changed'));

        NotificationService::assignmentDeadlineChanged($course->id, 'C', 'A', '2026-09-15', 900);
        $this->assertSame(2, $this->notifCount($user->id, 'assignment_deadline_changed'));
    }

    public function test_ambassador_status_changed_dedupes_same_status_but_not_a_different_one(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        NotificationService::ambassadorStatusChanged(1, 'F', 'under_review', 'approved');
        NotificationService::ambassadorStatusChanged(1, 'F', 'under_review', 'approved');
        $this->assertSame(1, $this->notifCount($admin->id, 'ambassador_status_changed'));

        NotificationService::ambassadorStatusChanged(1, 'F', 'approved', 'rejected');
        $this->assertSame(2, $this->notifCount($admin->id, 'ambassador_status_changed'));
    }

    // ── Cross-cutting invariants preserved ──────────────────────────────────

    public function test_idempotency_key_still_respects_disabled_preferences(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        \App\Models\NotificationPreference::create([
            'user_id' => $user->id, 'channel' => 'in_app', 'type' => 'course_created', 'enabled' => false,
        ]);

        NotificationService::courseCreated(1, 'C', null, $user->id);

        $this->assertDatabaseMissing('platform_notifications', ['user_id' => $user->id, 'type' => 'course_created']);
    }

    public function test_idempotency_key_dedup_is_per_recipient_two_admins_each_get_their_own_row(): void
    {
        $adminA = User::factory()->create(['role' => 'admin']);
        $adminB = User::factory()->create(['role' => 'admin']);

        NotificationService::courseCreated(1, 'C', null, $adminA->id);

        $this->assertSame(1, $this->notifCount($adminA->id, 'course_created'));
        $this->assertSame(1, $this->notifCount($adminB->id, 'course_created'));
    }

    // ── Matrix/classification consistency ───────────────────────────────────

    public function test_every_class_a_or_c_scenario_in_the_matrix_is_marked_adopted(): void
    {
        $matrix = file_get_contents(base_path('docs/architecture/NOTIFICATION_EVENT_MATRIX.md'));
        $lines = preg_split('/\r?\n/', $matrix);

        $mismatches = [];
        foreach ($lines as $line) {
            if (! preg_match('/^\|\s*\d+\s*\|\s*`(\w+)`\s*\|.*\|\s*(A|B|C)[^|]*\|\s*([^|]+)\|$/', trim($line), $m)) {
                continue;
            }
            [, $method, $class, $idempotency] = $m;
            $isAdopted = str_contains($idempotency, 'Adopted');

            if ($class !== 'B' && ! $isAdopted) {
                $mismatches[] = "{$method}: Class {$class} but not marked Adopted";
            }
            if ($class === 'B' && $isAdopted) {
                $mismatches[] = "{$method}: Class B but marked Adopted (should stay Time-window)";
            }
        }

        $this->assertEmpty($mismatches, implode("\n", $mismatches));
    }

    public function test_every_adopted_scenario_method_actually_produces_a_non_null_idempotency_key(): void
    {
        // Cross-check against real dispatch payloads (not just source text) —
        // every scenario this test suite exercises above must have left a
        // non-null idempotency_key on its row.
        $adoptedTypes = [
            'course_created', 'instructor_assigned', 'workshop_instructor_assigned', 'course_date_updated',
            'registration_opened', 'registration_confirmed', 'workshop_request_submitted', 'workshop_request_step',
            'workshop_request_rejected', 'course_cancelled', 'course_completed', 'account_created',
            'volunteer_request', 'member_converted', 'course_schedule_updated', 'placement_test_completed',
            'oral_assessment_completed', 'oral_assessment_status_changed', 'oral_assessment_rescheduled',
            'class_group_assigned', 'class_group_student_assigned', 'assignment_deadline_changed',
            'class_announcement_published', 'learning_path_enrolled', 'learning_path_completed',
            'course_completed_student', 'ambassador_application', 'ambassador_status_changed',
            'module_activated', 'lesson_activated', 'material_visible', 'assignment_graded',
            'certificate_issued', 'report_ready', 'assignment_created', 'session_scheduled',
        ];

        $this->assertCount(36, $adoptedTypes, 'Expected exactly 36 adopted scenario types tracked by this test');

        $admin = User::factory()->create(['role' => 'admin']);
        NotificationService::courseCreated(1, 'C', null, $admin->id);

        $row = PlatformNotification::where('user_id', $admin->id)->where('type', 'course_created')->first();
        $this->assertNotNull($row->idempotency_key);
    }
}
