<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAssessmentSetting;
use App\Models\Quiz;
use App\Models\QuizAttempt;

/**
 * Canonical, backend-only source of truth for a student's final course grade.
 *
 * Two components, weighted to always sum to 100 regardless of how many
 * quizzes exist:
 *   quiz_component_score  (weighted blend of every counts_toward_final_grade quiz)
 *   final_exam_score      (the one quiz flagged is_final_exam — reuses the same
 *                          quiz/question/attempt engine rather than a second one)
 *
 * No page should recompute this independently — always call
 * finalGradeBreakdown() and render its numbers as-is.
 */
class CourseAssessmentService
{
    /** Which attempt becomes the student's official score for a quiz. Kept as a code constant (not a DB setting) — no existing config surface for this cleanly, per audit. */
    private const OFFICIAL_ATTEMPT_POLICY = 'best';

    public function settingsFor(Course $course): CourseAssessmentSetting
    {
        return CourseAssessmentSetting::where('course_id', $course->id)->first()
            ?? new CourseAssessmentSetting(CourseAssessmentSetting::defaults($course->id));
    }

    /**
     * The student's official percentage for one quiz, per OFFICIAL_ATTEMPT_POLICY.
     * Returns null if the student has no graded attempt yet.
     */
    public function officialQuizScore(Quiz $quiz, int $userId, string $policy = self::OFFICIAL_ATTEMPT_POLICY): ?float
    {
        $attempts = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('user_id', $userId)
            ->get()
            ->filter(fn (QuizAttempt $a) => $a->isGraded());

        if ($attempts->isEmpty()) {
            return null;
        }

        return match ($policy) {
            'latest'  => (float) $attempts->sortByDesc(fn ($a) => $a->submitted_at ?? $a->created_at)->first()->score,
            'average' => round((float) $attempts->avg('score'), 2),
            default   => (float) $attempts->max('score'), // best
        };
    }

    /**
     * Quiz-component breakdown for a student: per-quiz scores/weights and the
     * blended component score. `status` is 'incomplete' when a required quiz
     * that's still open (not yet closed) hasn't been attempted — we don't
     * prematurely zero it while the student still has time (Phase 12).
     */
    public function quizComponentBreakdown(Course $course, int $userId, ?int $classGroupId, ?CourseAssessmentSetting $settings = null): array
    {
        $settings ??= $this->settingsFor($course);

        $quizzes = Quiz::forCourse($course->id)
            ->countsTowardFinalGrade()
            ->visibleForClassGroups($classGroupId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($quizzes->isEmpty()) {
            return [
                'status' => 'not_applicable',
                'component_score' => null,
                'quizzes' => [],
            ];
        }

        $equalWeight = round(100 / $quizzes->count(), 4);
        $incomplete = false;
        $rows = [];

        foreach ($quizzes as $quiz) {
            $score = $this->officialQuizScore($quiz, $userId);
            $closed = $quiz->effectiveStatus() === Quiz::STATUS_CLOSED;
            $weight = $settings->quiz_weighting_mode === CourseAssessmentSetting::MODE_CUSTOM
                ? (float) ($quiz->weight ?? 0)
                : $equalWeight;

            $effectiveScore = $score;
            if ($score === null) {
                if ($quiz->required && $closed) {
                    $effectiveScore = 0.0; // required quiz missed its window — contributes 0
                } elseif ($quiz->required) {
                    $incomplete = true; // still open — grade is pending, not zero
                } else {
                    $weight = 0.0; // optional, never attempted — excluded from the blend
                }
            }

            $rows[] = [
                'quiz_id' => $quiz->id,
                'title' => $quiz->title,
                'score' => $score,
                'effective_score' => $effectiveScore,
                'weight' => $weight,
                'required' => $quiz->required,
                'status' => $quiz->effectiveStatus(),
            ];
        }

        if ($incomplete) {
            return ['status' => 'incomplete', 'component_score' => null, 'quizzes' => $rows];
        }

        $totalWeight = array_sum(array_column($rows, 'weight'));
        $componentScore = $totalWeight > 0
            ? round(array_sum(array_map(fn ($r) => $r['effective_score'] * $r['weight'] / $totalWeight, $rows)), 2)
            : null;

        return ['status' => 'complete', 'component_score' => $componentScore, 'quizzes' => $rows];
    }

    /**
     * The single canonical final-grade calculation. Everything (student
     * result page, instructor student detail) must render exactly this.
     */
    public function finalGradeBreakdown(Course $course, int $userId, ?int $classGroupId = null): array
    {
        $settings = $this->settingsFor($course);
        $quizWeight = (float) $settings->quiz_component_weight;
        $examWeight = (float) $settings->final_exam_weight;

        $quizComponent = $this->quizComponentBreakdown($course, $userId, $classGroupId, $settings);

        $finalExam = Quiz::forCourse($course->id)->finalExam()->visibleForClassGroups($classGroupId)->first();
        $examScore = null;
        $examStatus = 'not_applicable';
        if ($finalExam) {
            $examScore = $this->officialQuizScore($finalExam, $userId);
            $examStatus = 'complete';
            if ($examScore === null) {
                $examStatus = $finalExam->effectiveStatus() === Quiz::STATUS_CLOSED ? 'complete' : 'incomplete';
                $examScore = $finalExam->effectiveStatus() === Quiz::STATUS_CLOSED ? 0.0 : null;
            }
        }

        $quizScore = $quizComponent['component_score'];
        $quizContribution = $quizScore !== null ? round($quizScore * $quizWeight / 100, 2) : null;
        $examContribution = $examScore !== null ? round($examScore * $examWeight / 100, 2) : null;

        // A zero-weight component with no quizzes/exam configured contributes
        // 0 rather than blocking the grade — this is what keeps an
        // unconfigured existing course (quiz_weight=0, no quizzes) resolving
        // to exam-only, per Phase 20 compatibility.
        $quizBlocking = $quizWeight > 0 && $quizComponent['status'] === 'incomplete';
        $examBlocking = $examWeight > 0 && $examStatus === 'incomplete';

        $finalScore = null;
        $status = 'incomplete';
        if (! $quizBlocking && ! $examBlocking) {
            $finalScore = round(($quizContribution ?? 0) + ($examContribution ?? 0), 2);
            $status = 'complete';
        }

        return [
            'status' => $status,
            'quiz_component_score' => $quizScore,
            'quiz_component_weight' => $quizWeight,
            'quiz_contribution' => $quizContribution,
            'quiz_breakdown' => $quizComponent['quizzes'],
            'final_exam_score' => $examScore,
            'final_exam_weight' => $examWeight,
            'final_exam_contribution' => $examContribution,
            'final_course_score' => $finalScore,
        ];
    }

    /** Used by the assessment-settings save endpoint. */
    public static function weightsValid(float $quizWeight, float $examWeight): bool
    {
        return CourseAssessmentSetting::weightsAreValid($quizWeight, $examWeight);
    }

    /** Used when saving custom per-quiz weights: the active (counted, required, attempted-or-open) set must sum to 100. */
    public static function customQuizWeightsValid(array $weights): bool
    {
        if (empty($weights)) {
            return true;
        }

        return abs(array_sum($weights) - 100.0) < 0.01;
    }
}
