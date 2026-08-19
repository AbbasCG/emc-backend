<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\CourseAssessmentSetting;
use App\Models\Instructor;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Registration;
use App\Models\User;
use App\Services\CourseAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseQuizFinalGradeTest extends TestCase
{
    use RefreshDatabase;

    // ─── Factories ──────────────────────────────────────────────────────────

    private function instructor(): array
    {
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $profile = Instructor::create(['user_id' => $user->id, 'name' => $user->name, 'title' => 'Instructor']);

        return [$user, $profile];
    }

    private function student(): User
    {
        return User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    private function course(Instructor $instructor): Course
    {
        return Course::factory()->create(['title' => 'English', 'status' => 'published', 'instructor_id' => $instructor->id, 'requires_placement_test' => true]);
    }

    private function enroll(User $student, Course $course): void
    {
        Registration::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'full_name' => $student->name, 'email' => $student->email, 'phone' => '0500000000', 'status' => 'registered',
        ]);
    }

    private function quiz(Course $course, array $overrides = []): Quiz
    {
        return Quiz::create(array_merge([
            'course_id' => $course->id,
            'title' => 'Quiz',
            'status' => Quiz::STATUS_ACTIVE,
            'max_attempts' => 1,
            'counts_toward_final_grade' => true,
            'required' => true,
        ], $overrides));
    }

    /** One 10-point MCQ question, so a given raw score maps 1:1 to a percentage. */
    private function addTenPointQuestion(Quiz $quiz): QuizQuestion
    {
        return $quiz->questions()->create([
            'question' => 'Q', 'type' => 'multiple_choice',
            'options' => ['a', 'b'], 'correct_answer' => ['a'], 'points' => 10, 'sort_order' => 0,
        ]);
    }

    private function gradedAttempt(Quiz $quiz, User $student, float $percentage, int $attemptNumber = 1): QuizAttempt
    {
        return QuizAttempt::create([
            'quiz_id' => $quiz->id, 'user_id' => $student->id, 'attempt_number' => $attemptNumber,
            'score' => $percentage, 'max_score' => 10, 'passed' => true,
            'answers' => ['1' => 'a'], 'status' => QuizAttempt::STATUS_GRADED,
            'started_at' => now(), 'submitted_at' => now(),
        ]);
    }

    // ── 1. Unlimited quizzes ────────────────────────────────────────────────

    public function test_instructor_can_create_more_than_a_hardcoded_number_of_quizzes(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        for ($i = 0; $i < 12; $i++) {
            $res = $this->actingAs($user)->postJson("/api/instructor/courses/{$course->id}/quizzes", [
                'title' => "Quiz {$i}",
            ]);
            $res->assertCreated();
        }

        $this->assertSame(12, Quiz::forCourse($course->id)->count());
    }

    // ── 2. Cross-instructor authorization ───────────────────────────────────

    public function test_instructor_cannot_create_quiz_for_another_instructors_course(): void
    {
        [, $ownerProfile] = $this->instructor();
        [$otherUser] = $this->instructor();
        $course = $this->course($ownerProfile);

        $this->actingAs($otherUser)
            ->postJson("/api/instructor/courses/{$course->id}/quizzes", ['title' => 'Hack'])
            ->assertStatus(403);
    }

    // ── English Institute gate: quizzes only for requires_placement_test courses ──

    public function test_instructor_cannot_manage_quizzes_for_a_non_english_course(): void
    {
        [$user, $instructor] = $this->instructor();
        $nonEnglishCourse = Course::factory()->create([
            'title' => 'Programming', 'status' => 'published',
            'instructor_id' => $instructor->id, 'requires_placement_test' => false,
        ]);

        $this->actingAs($user)
            ->postJson("/api/instructor/courses/{$nonEnglishCourse->id}/quizzes", ['title' => 'Quiz'])
            ->assertStatus(403);

        $this->actingAs($user)
            ->getJson("/api/instructor/courses/{$nonEnglishCourse->id}/quizzes")
            ->assertStatus(403);
    }

    public function test_auth_me_reports_has_english_courses_flag_correctly(): void
    {
        [$withUser, $withInstructor] = $this->instructor();
        $this->quiz($this->course($withInstructor)); // course() defaults requires_placement_test=true

        [$withoutUser, $withoutInstructor] = $this->instructor();
        Course::factory()->create([
            'instructor_id' => $withoutInstructor->id, 'requires_placement_test' => false, 'status' => 'published',
        ]);

        $this->actingAs($withUser)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.has_english_courses', true);

        // Regression: /auth/login must report the same flag as /auth/me — the
        // original bug was that a fresh in-SPA login left has_english_courses
        // undefined until a full page reload re-hydrated from /auth/me.
        $this->postJson('/api/auth/login', ['email' => $withUser->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.has_english_courses', true);

        $this->actingAs($withoutUser)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.has_english_courses', false);
    }

    // ── 3-6. Visibility / scheduling / attempt limits ───────────────────────

    public function test_draft_quiz_is_hidden_from_students(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $this->quiz($course, ['status' => Quiz::STATUS_DRAFT]);

        $res = $this->actingAs($student)->getJson("/api/student/courses/{$course->id}/quizzes");
        $res->assertOk();
        $this->assertCount(0, $res->json('data'));
    }

    public function test_active_quiz_is_visible_to_students(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $this->quiz($course, ['status' => Quiz::STATUS_ACTIVE, 'title' => 'Visible']);

        $res = $this->actingAs($student)->getJson("/api/student/courses/{$course->id}/quizzes");
        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Visible', $res->json('data.0.title'));
    }

    public function test_scheduled_quiz_becomes_available_once_opens_at_passes(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $quiz = $this->quiz($course, [
            'status' => Quiz::STATUS_SCHEDULED,
            'opens_at' => now()->subMinute(),
            'closes_at' => now()->addDay(),
        ]);

        $this->assertSame(Quiz::STATUS_ACTIVE, $quiz->fresh()->effectiveStatus());

        $future = $this->quiz($course, [
            'status' => Quiz::STATUS_SCHEDULED,
            'opens_at' => now()->addDay(),
        ]);
        $this->assertSame(Quiz::STATUS_SCHEDULED, $future->fresh()->effectiveStatus());
    }

    public function test_closed_quiz_blocks_new_attempts(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $quiz = $this->quiz($course, ['status' => Quiz::STATUS_CLOSED]);
        $this->addTenPointQuestion($quiz);

        $this->actingAs($student)
            ->postJson("/api/student/quizzes/{$quiz->id}/submit", ['answers' => ['1' => 'a']])
            ->assertStatus(422);
    }

    public function test_attempt_limit_is_enforced(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $quiz = $this->quiz($course, ['max_attempts' => 1]);
        $this->addTenPointQuestion($quiz);

        $this->actingAs($student)->postJson("/api/student/quizzes/{$quiz->id}/submit", ['answers' => ['1' => 'a']])->assertOk();
        $this->actingAs($student)->postJson("/api/student/quizzes/{$quiz->id}/submit", ['answers' => ['1' => 'a']])->assertStatus(422);

        $this->assertSame(1, QuizAttempt::where('quiz_id', $quiz->id)->where('user_id', $student->id)->count());
    }

    // ── 8-9. Auto grading + best-attempt policy ─────────────────────────────

    public function test_automatic_grading_and_best_attempt_becomes_official_score(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $quiz = $this->quiz($course, ['max_attempts' => 3]);

        $this->gradedAttempt($quiz, $student, 60.0, 1);
        $this->gradedAttempt($quiz, $student, 85.0, 2);

        $service = app(CourseAssessmentService::class);
        $this->assertSame(85.0, $service->officialQuizScore($quiz, $student->id));
    }

    // ── 10-11. Equal vs custom weighting ────────────────────────────────────

    public function test_equal_weighting_averages_quizzes_evenly(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $q1 = $this->quiz($course, ['title' => 'Q1']);
        $q2 = $this->quiz($course, ['title' => 'Q2']);
        $q3 = $this->quiz($course, ['title' => 'Q3']);
        $this->gradedAttempt($q1, $student, 80.0);
        $this->gradedAttempt($q2, $student, 60.0);
        $this->gradedAttempt($q3, $student, 100.0);

        CourseAssessmentSetting::create(['course_id' => $course->id, 'quiz_component_weight' => 30, 'final_exam_weight' => 70, 'quiz_weighting_mode' => 'equal']);

        $service = app(CourseAssessmentService::class);
        $breakdown = $service->quizComponentBreakdown($course, $student->id, null);
        $this->assertSame(80.0, $breakdown['component_score']); // (80+60+100)/3 = 80
    }

    public function test_custom_weighting_uses_instructor_defined_weights(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $q1 = $this->quiz($course, ['title' => 'Q1', 'weight' => 20]);
        $q2 = $this->quiz($course, ['title' => 'Q2', 'weight' => 30]);
        $q3 = $this->quiz($course, ['title' => 'Q3', 'weight' => 50]);
        $this->gradedAttempt($q1, $student, 100.0);
        $this->gradedAttempt($q2, $student, 100.0);
        $this->gradedAttempt($q3, $student, 40.0);

        CourseAssessmentSetting::create(['course_id' => $course->id, 'quiz_component_weight' => 30, 'final_exam_weight' => 70, 'quiz_weighting_mode' => 'custom']);

        $service = app(CourseAssessmentService::class);
        $breakdown = $service->quizComponentBreakdown($course, $student->id, null);
        // 100*.2 + 100*.3 + 40*.5 = 20 + 30 + 20 = 70
        $this->assertSame(70.0, $breakdown['component_score']);
    }

    // ── 12. Component + exam weight must total 100 ──────────────────────────

    public function test_component_weight_save_rejects_totals_other_than_100(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        $this->actingAs($user)->putJson("/api/instructor/courses/{$course->id}/assessment-settings", [
            'quiz_component_weight' => 40, 'final_exam_weight' => 50, 'quiz_weighting_mode' => 'equal',
        ])->assertStatus(422);

        $this->actingAs($user)->putJson("/api/instructor/courses/{$course->id}/assessment-settings", [
            'quiz_component_weight' => 30, 'final_exam_weight' => 70, 'quiz_weighting_mode' => 'equal',
        ])->assertOk();
    }

    // ── 13. Final grade formula ──────────────────────────────────────────────

    public function test_final_grade_formula_matches_the_spec_example(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $q1 = $this->quiz($course, ['title' => 'Q1']);
        $q2 = $this->quiz($course, ['title' => 'Q2']);
        $q3 = $this->quiz($course, ['title' => 'Q3']);
        $this->gradedAttempt($q1, $student, 80.0);
        $this->gradedAttempt($q2, $student, 60.0);
        $this->gradedAttempt($q3, $student, 100.0);

        $exam = $this->quiz($course, ['title' => 'Final Exam', 'is_final_exam' => true, 'counts_toward_final_grade' => false]);
        $this->gradedAttempt($exam, $student, 70.0);

        CourseAssessmentSetting::create(['course_id' => $course->id, 'quiz_component_weight' => 30, 'final_exam_weight' => 70, 'quiz_weighting_mode' => 'equal']);

        $breakdown = app(CourseAssessmentService::class)->finalGradeBreakdown($course, $student->id);

        $this->assertSame('complete', $breakdown['status']);
        $this->assertSame(80.0, $breakdown['quiz_component_score']);
        $this->assertSame(24.0, $breakdown['quiz_contribution']);
        $this->assertSame(70.0, $breakdown['final_exam_score']);
        $this->assertSame(49.0, $breakdown['final_exam_contribution']);
        $this->assertSame(73.0, $breakdown['final_course_score']);
    }

    // ── 14. Adding more quizzes never raises the maximum ────────────────────

    public function test_adding_more_quizzes_does_not_increase_the_maximum_final_score(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        for ($i = 0; $i < 8; $i++) {
            $q = $this->quiz($course, ['title' => "Q{$i}"]);
            $this->gradedAttempt($q, $student, 100.0);
        }
        $exam = $this->quiz($course, ['title' => 'Exam', 'is_final_exam' => true, 'counts_toward_final_grade' => false]);
        $this->gradedAttempt($exam, $student, 100.0);

        CourseAssessmentSetting::create(['course_id' => $course->id, 'quiz_component_weight' => 30, 'final_exam_weight' => 70, 'quiz_weighting_mode' => 'equal']);

        $breakdown = app(CourseAssessmentService::class)->finalGradeBreakdown($course, $student->id);
        $this->assertSame(100.0, $breakdown['final_course_score']);
    }

    // ── 15. Missing required, closed quiz contributes 0 ─────────────────────

    public function test_missing_closed_required_quiz_contributes_zero(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $done = $this->quiz($course, ['title' => 'Done']);
        $this->gradedAttempt($done, $student, 100.0);
        $this->quiz($course, ['title' => 'Missed', 'status' => Quiz::STATUS_CLOSED, 'required' => true]);

        $breakdown = app(CourseAssessmentService::class)->quizComponentBreakdown($course, $student->id, null);
        $this->assertSame('complete', $breakdown['status']);
        $this->assertSame(50.0, $breakdown['component_score']); // (100 + 0) / 2
    }

    public function test_missing_still_open_required_quiz_is_incomplete_not_zero(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $this->quiz($course, ['title' => 'Open', 'status' => Quiz::STATUS_ACTIVE, 'required' => true]);

        $breakdown = app(CourseAssessmentService::class)->quizComponentBreakdown($course, $student->id, null);
        $this->assertSame('incomplete', $breakdown['status']);
        $this->assertNull($breakdown['component_score']);
    }

    // ── 16. Existing course with no quizzes stays 100% exam ─────────────────

    public function test_course_with_no_quiz_configuration_remains_100_percent_final_exam(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $exam = $this->quiz($course, ['title' => 'Exam', 'is_final_exam' => true, 'counts_toward_final_grade' => false]);
        $this->gradedAttempt($exam, $student, 88.0);

        // No CourseAssessmentSetting row created — defaults apply.
        $breakdown = app(CourseAssessmentService::class)->finalGradeBreakdown($course, $student->id);
        $this->assertSame(0.0, $breakdown['quiz_component_weight']);
        $this->assertSame(100.0, $breakdown['final_exam_weight']);
        $this->assertSame(88.0, $breakdown['final_course_score']);
    }

    // ── 17. Class-scoped IDOR ────────────────────────────────────────────────

    public function test_student_from_another_class_cannot_access_class_scoped_quiz(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        $groupA = ClassGroup::create(['course_id' => $course->id, 'instructor_id' => $instructor->id, 'level_code' => 'A1', 'name' => 'A1', 'capacity' => 10, 'status' => 'active']);
        $groupB = ClassGroup::create(['course_id' => $course->id, 'instructor_id' => $instructor->id, 'level_code' => 'B1', 'name' => 'B1', 'capacity' => 10, 'status' => 'active']);

        $studentA = $this->student();
        $this->enroll($studentA, $course);
        ClassGroupStudent::create(['class_group_id' => $groupA->id, 'user_id' => $studentA->id, 'status' => 'assigned', 'assigned_at' => now()]);

        $quizForB = $this->quiz($course, ['title' => 'B1 Quiz', 'class_group_id' => $groupB->id]);
        $this->addTenPointQuestion($quizForB);

        $list = $this->actingAs($studentA)->getJson("/api/student/courses/{$course->id}/quizzes");
        $list->assertOk();
        $this->assertCount(0, $list->json('data'));

        $this->actingAs($studentA)
            ->postJson("/api/student/quizzes/{$quizForB->id}/submit", ['answers' => ['1' => 'a']])
            ->assertStatus(403);
    }

    // ── 18. Cannot submit another user's attempt ─────────────────────────────

    public function test_student_can_only_submit_their_own_attempt(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $victim = $this->student();
        $attacker = $this->student();
        $this->enroll($victim, $course);
        $this->enroll($attacker, $course);

        $quiz = $this->quiz($course);
        $this->addTenPointQuestion($quiz);

        $this->actingAs($attacker)->postJson("/api/student/quizzes/{$quiz->id}/submit", ['answers' => ['1' => 'a']])->assertOk();

        $this->assertSame(1, QuizAttempt::where('quiz_id', $quiz->id)->where('user_id', $attacker->id)->count());
        $this->assertSame(0, QuizAttempt::where('quiz_id', $quiz->id)->where('user_id', $victim->id)->count());
    }

    // ── 19-20. Instructor student-detail + final-result endpoints ───────────

    public function test_instructor_student_breakdown_endpoint_returns_canonical_breakdown(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $q1 = $this->quiz($course, ['title' => 'Q1']);
        $this->gradedAttempt($q1, $student, 90.0);
        CourseAssessmentSetting::create(['course_id' => $course->id, 'quiz_component_weight' => 30, 'final_exam_weight' => 70, 'quiz_weighting_mode' => 'equal']);

        $res = $this->actingAs($user)->getJson("/api/instructor/courses/{$course->id}/students/{$student->id}/quiz-breakdown");
        $res->assertOk();
        $this->assertEquals(90.0, $res->json('data.quiz_component_score'));
        $this->assertEquals(27.0, $res->json('data.quiz_contribution'));
    }

    public function test_student_final_grade_endpoint_returns_canonical_breakdown(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $exam = $this->quiz($course, ['title' => 'Exam', 'is_final_exam' => true, 'counts_toward_final_grade' => false]);
        $this->gradedAttempt($exam, $student, 75.0);

        $res = $this->actingAs($student)->getJson("/api/student/courses/{$course->id}/final-grade");
        $res->assertOk();
        $this->assertEquals(75.0, $res->json('data.final_course_score'));
    }
}
