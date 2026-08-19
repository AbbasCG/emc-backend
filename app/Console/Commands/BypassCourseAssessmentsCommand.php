<?php

namespace App\Console\Commands;

use App\Enums\RegistrationStatus;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\OralAssessmentBooking;
use App\Models\OralAssessmentSlot;
use App\Models\PlacementTest;
use App\Models\PlacementTestAttempt;
use App\Models\Registration;
use App\Models\User;
use App\Services\PlacementProgressService;
use App\Services\PlacementScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Administrative bypass for a whitelisted set of already-paid, already-registered
 * students on a single course: marks the written placement test and oral
 * assessment as completed so PlacementProgressService::deriveStatus() resolves
 * to STATUS_COMPLETED and CourseAccessEligibilityService grants course access.
 *
 * Scope is strictly course_id + explicit --users list. Does not touch
 * courses.requires_placement_test or any student outside the list.
 */
class BypassCourseAssessmentsCommand extends Command
{
    protected $signature = 'course:bypass-assessments
        {courseId : The course to grant access to}
        {--users= : Comma-separated list of user IDs to process}
        {--level=B1 : CEFR level code to record as estimated_level/final_level}
        {--placement-test= : Override placement_test_id (defaults to the first active test)}
        {--instructor= : Override instructor_id (defaults to the course\'s own instructor_id)}
        {--dry-run : Preview actions without writing to the database}';

    protected $description = 'Mark placement + oral assessment requirements as satisfied for specific already-registered students on one course, without altering global course settings.';

    private const ALLOWED_LEVELS = ['Starter', 'A1', 'A2', 'B1', 'B2', 'C1'];

    public function handle(PlacementProgressService $progressService): int
    {
        $courseId = (int) $this->argument('courseId');
        $dryRun   = (bool) $this->option('dry-run');
        $level    = (string) $this->option('level');

        if (! in_array($level, self::ALLOWED_LEVELS, true)) {
            $this->error("Invalid --level '{$level}'. Allowed: ".implode(', ', self::ALLOWED_LEVELS));

            return self::FAILURE;
        }

        $usersOption = (string) $this->option('users');
        if ($usersOption === '') {
            $this->error('Provide --users=1,2,3');

            return self::FAILURE;
        }
        $userIds = collect(explode(',', $usersOption))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            $this->error('No valid user IDs parsed from --users.');

            return self::FAILURE;
        }

        $course = Course::find($courseId);
        if (! $course) {
            $this->error("Course {$courseId} not found.");

            return self::FAILURE;
        }

        $placementTestId = $this->option('placement-test');
        $placementTest = $placementTestId
            ? PlacementTest::find((int) $placementTestId)
            : PlacementTest::active()->orderBy('id')->first();

        if (! $placementTest) {
            $this->error('No active placement test found (and none given via --placement-test). Aborting — refusing to guess a placement_test_id.');

            return self::FAILURE;
        }

        $instructorId = $this->option('instructor')
            ? (int) $this->option('instructor')
            : $course->instructor_id;

        if (! $instructorId || ! Instructor::whereKey($instructorId)->exists()) {
            $this->error("No valid instructor resolved for course {$courseId} (course.instructor_id is empty or invalid, and no --instructor override given). Aborting — refusing to guess an instructor_id.");

            return self::FAILURE;
        }

        $levelDetails = PlacementScoringService::levelFromCode($level);
        $scoreBand = collect(PlacementScoringService::LEVEL_MAP)->first(fn ($row) => $row[2] === $level);
        $representativeScore = $scoreBand ? (int) round(($scoreBand[0] + $scoreBand[1]) / 2) : 40;

        $this->info(($dryRun ? '[DRY RUN] ' : '')."course_id={$course->id} placement_test_id={$placementTest->id} instructor_id={$instructorId} level={$level}");

        $slot = $this->resolveBypassSlot($course->id, $instructorId, $dryRun);
        if (! $slot) {
            $this->error('Could not resolve or create the administrative bypass oral_assessment_slots row.');

            return self::FAILURE;
        }

        $rows = [];
        $summary = [
            'processed' => 0,
            'attempts_created' => 0,
            'bookings_created' => 0,
            'already_satisfied' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($userIds as $userId) {
            $summary['processed']++;

            try {
                $result = $dryRun
                    ? $this->simulateUser($course, $userId, $placementTest, $instructorId, $slot, $level, $representativeScore, $progressService)
                    : DB::transaction(fn () => $this->processUser($course, $userId, $placementTest, $instructorId, $slot, $level, $representativeScore, $progressService));

                $rows[] = $result;

                match ($result['outcome']) {
                    'skipped' => $summary['skipped']++,
                    'already_satisfied' => $summary['already_satisfied']++,
                    default => null,
                };
                if ($result['attempt_action'] === 'created') {
                    $summary['attempts_created']++;
                }
                if ($result['booking_action'] === 'created') {
                    $summary['bookings_created']++;
                }
            } catch (\Throwable $e) {
                $summary['failed']++;
                $rows[] = [
                    'user_id' => $userId,
                    'name' => optional(User::find($userId))->name ?? '(unknown)',
                    'registration' => 'error',
                    'attempt_action' => '-',
                    'booking_action' => '-',
                    'final_status' => 'FAILED',
                    'outcome' => 'failed',
                    'note' => $e->getMessage(),
                ];
                $this->error("User {$userId} failed: {$e->getMessage()}");
            }
        }

        $this->table(
            ['user_id', 'name', 'registration', 'attempt', 'booking', 'final_status', 'note'],
            collect($rows)->map(fn ($r) => [
                $r['user_id'], $r['name'], $r['registration'], $r['attempt_action'],
                $r['booking_action'], $r['final_status'], $r['note'],
            ])
        );

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '').'Summary:');
        $this->line("Processed: {$summary['processed']}");
        $this->line("Placement attempts created: {$summary['attempts_created']}");
        $this->line("Oral bookings created: {$summary['bookings_created']}");
        $this->line("Already satisfied: {$summary['already_satisfied']}");
        $this->line("Skipped: {$summary['skipped']}");
        $this->line("Failed: {$summary['failed']}");

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Reuse (or, outside dry-run, create) one shared synthetic slot per course
     * used only to satisfy the NOT NULL slot_id FK on bypass bookings. Tagged
     * via `notes` so it's never mistaken for a real bookable slot.
     */
    private function resolveBypassSlot(int $courseId, int $instructorId, bool $dryRun): ?OralAssessmentSlot
    {
        $marker = 'ADMIN_BYPASS_SLOT (auto-generated — do not book, do not delete real slots matching this pattern)';

        $existing = OralAssessmentSlot::where('course_id', $courseId)
            ->where('instructor_id', $instructorId)
            ->where('notes', $marker)
            ->first();

        if ($existing) {
            return $existing;
        }

        if ($dryRun) {
            $placeholder = new OralAssessmentSlot([
                'course_id' => $courseId,
                'instructor_id' => $instructorId,
                'starts_at' => now(),
                'ends_at' => now()->addMinutes(15),
                'is_booked' => true,
                'booking_status' => 'completed',
                'notes' => $marker,
            ]);
            $this->line('[DRY RUN] Would create bypass slot (course_id='.$courseId.', instructor_id='.$instructorId.')');

            return $placeholder;
        }

        return OralAssessmentSlot::create([
            'course_id' => $courseId,
            'instructor_id' => $instructorId,
            'starts_at' => now(),
            'ends_at' => now()->addMinutes(15),
            'is_booked' => true,
            'booking_status' => 'completed',
            'notes' => $marker,
        ]);
    }

    private function simulateUser(
        Course $course, int $userId, PlacementTest $placementTest, int $instructorId,
        OralAssessmentSlot $slot, string $level, int $score, PlacementProgressService $progressService
    ): array {
        $user = User::find($userId);
        if (! $user) {
            return $this->row($userId, '(not found)', 'user missing', '-', '-', 'SKIPPED', 'skipped', 'user_id does not exist');
        }

        $registration = Registration::where('course_id', $course->id)->where('user_id', $userId)->first();
        if (! $registration) {
            return $this->row($userId, $user->name, 'not found', '-', '-', 'SKIPPED', 'skipped', 'no registration for this course');
        }
        if (! in_array($registration->status, RegistrationStatus::activeEnrollment(), true)) {
            return $this->row($userId, $user->name, $registration->status, '-', '-', 'SKIPPED', 'skipped', 'registration not in an active-enrollment status');
        }

        $attempt = PlacementTestAttempt::where('user_id', $userId)->where('course_id', $course->id)->first();
        $attemptAction = ! $attempt ? 'would create' : ($attempt->status === 'completed' ? 'already completed' : 'would update to completed');

        $booking = OralAssessmentBooking::where('user_id', $userId)->where('course_id', $course->id)->first();
        $bookingAction = ! $booking ? 'would create' : (($booking->status === 'completed' && $booking->final_level) ? 'already completed' : 'would update to completed');

        $bothAlreadyDone = $attempt && $attempt->status === 'completed' && $booking && $booking->status === 'completed' && $booking->final_level;

        return $this->row(
            $userId, $user->name, $registration->status, $attemptAction, $bookingAction,
            'would be COMPLETED (can_access_course=true)',
            $bothAlreadyDone ? 'already_satisfied' : 'would_process',
            $bothAlreadyDone ? 'no changes needed' : "would set level={$level}"
        );
    }

    private function processUser(
        Course $course, int $userId, PlacementTest $placementTest, int $instructorId,
        OralAssessmentSlot $slot, string $level, int $score, PlacementProgressService $progressService
    ): array {
        $user = User::find($userId);
        if (! $user) {
            return $this->row($userId, '(not found)', 'user missing', '-', '-', 'SKIPPED', 'skipped', 'user_id does not exist');
        }

        $registration = Registration::where('course_id', $course->id)->where('user_id', $userId)->first();
        if (! $registration) {
            return $this->row($userId, $user->name, 'not found', '-', '-', 'SKIPPED', 'skipped', 'no registration for this course');
        }
        if (! in_array($registration->status, RegistrationStatus::activeEnrollment(), true)) {
            return $this->row($userId, $user->name, $registration->status, '-', '-', 'SKIPPED', 'skipped', 'registration not in an active-enrollment status');
        }

        // --- Placement test attempt: firstOrNew keyed on the same (user_id, course_id) unique index. ---
        $attempt = PlacementTestAttempt::firstOrNew([
            'user_id' => $userId,
            'course_id' => $course->id,
        ]);
        $attemptExisted = $attempt->exists;
        $attemptChanged = false;

        if (! $attemptExisted) {
            $attempt->placement_test_id = $placementTest->id;
            $attempt->total_questions = $placementTest->total_questions;
            $attempt->started_at = now();
            $attemptChanged = true;
        }
        if ($attempt->status !== 'completed') {
            $attempt->status = 'completed';
            $attempt->submitted_at = $attempt->submitted_at ?? now();
            $attempt->estimated_level = $attempt->estimated_level ?: $level;
            $attempt->score = $attempt->score ?: $score;
            $attempt->percentage = $attempt->percentage ?: round(($score / max($placementTest->total_questions, 1)) * 100, 2);
            $attemptChanged = true;
        }
        if ($attemptChanged) {
            $attempt->save();
        }

        // --- Oral assessment booking: same firstOrNew pattern, unique on (user_id, course_id). ---
        $booking = OralAssessmentBooking::firstOrNew([
            'user_id' => $userId,
            'course_id' => $course->id,
        ]);
        $bookingExisted = $booking->exists;
        $bookingChanged = false;

        if (! $bookingExisted) {
            $booking->instructor_id = $instructorId;
            $booking->slot_id = $slot->id;
            $booking->placement_test_attempt_id = $attempt->id;
            $bookingChanged = true;
        }
        if ($booking->status !== 'completed' || ! $booking->final_level) {
            $booking->status = 'completed';
            $booking->final_level = $booking->final_level ?: $level;
            $booking->oral_score = $booking->oral_score ?: $score;
            $booking->evaluated_at = $booking->evaluated_at ?? now();
            $booking->instructor_notes = $booking->instructor_notes ?: 'Administrative bypass — placement/oral requirement waived by admin for a pre-paid registration.';
            $bookingChanged = true;
        }
        if ($bookingChanged) {
            $booking->save();
        }

        $status = $progressService->deriveStatus($attempt->fresh(), $booking->fresh());
        $canAccess = $status === PlacementProgressService::STATUS_COMPLETED;

        $outcome = (! $attemptChanged && ! $bookingChanged) ? 'already_satisfied' : 'processed';

        return $this->row(
            $userId,
            $user->name,
            $registration->status,
            $attemptExisted ? ($attemptChanged ? 'updated' : 'unchanged') : 'created',
            $bookingExisted ? ($bookingChanged ? 'updated' : 'unchanged') : 'created',
            $canAccess ? 'COMPLETED (can_access_course=true)' : "INCOMPLETE ({$status})",
            $outcome,
            $canAccess ? '' : 'WARNING: derived status did not resolve to completed — inspect manually'
        );
    }

    private function row(int $userId, string $name, string $registration, string $attemptAction, string $bookingAction, string $finalStatus, string $outcome, string $note): array
    {
        return [
            'user_id' => $userId,
            'name' => $name,
            'registration' => $registration,
            'attempt_action' => $attemptAction,
            'booking_action' => $bookingAction,
            'final_status' => $finalStatus,
            'outcome' => $outcome,
            'note' => $note,
        ];
    }
}
