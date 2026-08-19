<?php

namespace App\Http\Controllers\Student;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Registration;
use App\Services\CourseAssessmentService;
use App\Services\QuizScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * الاختبارات القصيرة — student-facing quiz list, attempt submission, and the
 * canonical final-grade breakdown for one course. Class-scoped: a student
 * only ever sees course-wide quizzes plus quizzes for their own active class
 * (same resolution pattern as CertificateEligibilityService::resolveClassGroupForUser).
 */
class StudentQuizController extends Controller
{
    private function requireEnrollment(Request $request, int $courseId): void
    {
        $enrolled = Registration::where('user_id', $request->user()->id)
            ->where('course_id', $courseId)
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->exists();

        abort_unless($enrolled, 403, 'أنت غير مسجل في هذه الدورة.');
    }

    private function activeClassGroupId(int $userId, int $courseId): ?int
    {
        return ClassGroupStudent::where('user_id', $userId)
            ->whereIn('status', ClassGroupStudent::ACTIVE_STATUSES)
            ->whereHas('classGroup', fn ($q) => $q->where('course_id', $courseId))
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->value('class_group_id');
    }

    // ─── GET /courses/{course}/quizzes ─────────────────────────────────────

    public function index(Request $request, int $course): JsonResponse
    {
        $this->requireEnrollment($request, $course);
        $userId = $request->user()->id;
        $classGroupId = $this->activeClassGroupId($userId, $course);

        $quizzes = Quiz::forCourse($course)
            ->visibleForClassGroups($classGroupId)
            ->where('is_final_exam', false)
            ->withCount('questions')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter->isVisibleToStudents();

        $data = $quizzes->map(fn (Quiz $quiz) => $this->presentForStudent($quiz, $userId));

        return response()->json(['success' => true, 'data' => $data->values()]);
    }

    // ─── GET /quizzes/{quiz} (take view — hides correct answers) ───────────

    public function show(Request $request, Quiz $quiz): JsonResponse
    {
        $courseId = $quiz->course_id ?? $quiz->lesson?->module?->course_id;
        abort_unless($courseId, 404);
        $this->requireEnrollment($request, $courseId);

        $userId = $request->user()->id;
        $classGroupId = $this->activeClassGroupId($userId, $courseId);
        if ($quiz->class_group_id && $quiz->class_group_id !== $classGroupId) {
            abort(403, 'هذا الاختبار مخصص لمجموعة أخرى.');
        }
        abort_unless($quiz->isVisibleToStudents(), 404);
        abort_unless($quiz->acceptsNewAttempts(), 422, 'هذا الاختبار غير متاح حالياً.');

        $usedAttempts = QuizAttempt::where('quiz_id', $quiz->id)->where('user_id', $userId)->count();
        abort_if($usedAttempts >= $quiz->max_attempts, 422, 'لقد استنفدت عدد المحاولات المسموح بها لهذا الاختبار.');

        $questions = $quiz->questions()->get();
        if ($quiz->shuffle_questions) {
            $questions = $questions->shuffle()->values();
        }

        return response()->json(['success' => true, 'data' => [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'max_attempts' => $quiz->max_attempts,
            'attempts_used' => $usedAttempts,
            'closes_at' => $quiz->closes_at?->toIso8601String(),
            'questions' => $questions->map(function ($q) use ($quiz) {
                $options = $q->options;
                if ($quiz->shuffle_answers && is_array($options)) {
                    shuffle($options);
                }

                return [
                    'id' => $q->id,
                    'question' => $q->question,
                    'type' => $q->type,
                    'options' => $options,
                    'points' => $q->points,
                ];
            }),
        ]]);
    }

    // ─── POST /quizzes/{quiz}/submit ────────────────────────────────────────

    public function submit(Request $request, Quiz $quiz, QuizScoringService $scorer): JsonResponse
    {
        $courseId = $quiz->course_id ?? $quiz->lesson?->module?->course_id;
        abort_unless($courseId, 404);
        $this->requireEnrollment($request, $courseId);

        $userId = $request->user()->id;
        $classGroupId = $this->activeClassGroupId($userId, $courseId);
        if ($quiz->class_group_id && $quiz->class_group_id !== $classGroupId) {
            abort(403, 'هذا الاختبار مخصص لمجموعة أخرى.');
        }
        abort_unless($quiz->acceptsNewAttempts(), 422, 'هذا الاختبار غير متاح حالياً لاستقبال محاولات جديدة.');

        $data = $request->validate(['answers' => 'required|array']);

        $attempt = DB::transaction(function () use ($quiz, $userId, $data, $scorer) {
            // Lock the attempt-count check + insert together so two concurrent
            // submits from the same student can't both slip past max_attempts.
            $usedAttempts = QuizAttempt::where('quiz_id', $quiz->id)->where('user_id', $userId)->lockForUpdate()->count();
            if ($usedAttempts >= $quiz->max_attempts) {
                abort(422, 'لقد استنفدت عدد المحاولات المسموح بها لهذا الاختبار.');
            }

            $result = $scorer->score($quiz, $data['answers']);

            return QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'user_id' => $userId,
                'attempt_number' => $usedAttempts + 1,
                'score' => $result['score'],
                'max_score' => $result['total_points'],
                'passed' => $result['passed'],
                'answers' => $data['answers'],
                'status' => QuizAttempt::STATUS_GRADED,
                'started_at' => now(),
                'submitted_at' => now(),
            ]);
        });

        return response()->json(['success' => true, 'data' => [
            'attempt_id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'score' => (float) $attempt->score,
            'passed' => $attempt->passed,
            'show_result' => $quiz->show_result_after_submission,
            'show_correct_answers' => $quiz->show_correct_answers,
        ]]);
    }

    // ─── GET /courses/{course}/final-grade ──────────────────────────────────

    public function finalGrade(Request $request, int $course, CourseAssessmentService $service): JsonResponse
    {
        $this->requireEnrollment($request, $course);
        $userId = $request->user()->id;
        $classGroupId = $this->activeClassGroupId($userId, $course);

        $courseModel = Course::findOrFail($course);
        $breakdown = $service->finalGradeBreakdown($courseModel, $userId, $classGroupId);

        return response()->json(['success' => true, 'data' => $breakdown]);
    }

    // ─── Private ────────────────────────────────────────────────────────────

    private function presentForStudent(Quiz $quiz, int $userId): array
    {
        $attempts = QuizAttempt::where('quiz_id', $quiz->id)->where('user_id', $userId)->get();
        $graded = $attempts->filter(fn ($a) => $a->isGraded());

        return [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'status' => $quiz->effectiveStatus(),
            'opens_at' => $quiz->opens_at?->toIso8601String(),
            'closes_at' => $quiz->closes_at?->toIso8601String(),
            'questions_count' => $quiz->questions_count,
            'max_attempts' => $quiz->max_attempts,
            'attempts_used' => $attempts->count(),
            'best_score' => $graded->count() ? (float) $graded->max('score') : null,
            'completed' => $graded->count() > 0,
            'counts_toward_final_grade' => $quiz->counts_toward_final_grade,
        ];
    }
}
