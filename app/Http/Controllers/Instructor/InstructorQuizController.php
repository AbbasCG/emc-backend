<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Instructor-facing quiz authoring/lifecycle management, scoped strictly to
 * courses the requesting instructor owns (courses.instructor_id). No fixed
 * quiz count anywhere here — a course may have any number of course_quizzes-
 * equivalent rows; numbering shown to instructors is display-only (`position`).
 */
class InstructorQuizController extends Controller
{
    private function ownedCourse(Request $request, int $courseId): Course
    {
        $profile = $request->user()->instructorProfile;
        abort_unless($profile, 403, 'لا يوجد ملف مدرب مرتبط بحسابك.');

        $course = Course::where('id', $courseId)->where('instructor_id', $profile->id)->first();
        abort_unless($course, 403, 'ليس لديك صلاحية الوصول إلى هذه الدورة.');

        // Quizzes/final-exam are an English-Institute feature: gated per-course
        // on requires_placement_test — never on role/department/title — so a
        // course outside that program never gets a quiz-management surface.
        abort_unless((bool) $course->requires_placement_test, 403, 'الاختبارات القصيرة متاحة فقط لدورات المعهد الإنجليزي التي تتطلب اختبار تحديد المستوى.');

        return $course;
    }

    private function ownedQuiz(Request $request, Quiz $quiz): Quiz
    {
        $profile = $request->user()->instructorProfile;
        abort_unless($profile, 403, 'لا يوجد ملف مدرب مرتبط بحسابك.');

        $course = Course::where('id', $quiz->course_id)->where('instructor_id', $profile->id)->first();
        abort_unless($course, 403, 'ليس لديك صلاحية الوصول إلى هذا الاختبار.');
        abort_unless((bool) $course->requires_placement_test, 403, 'الاختبارات القصيرة متاحة فقط لدورات المعهد الإنجليزي التي تتطلب اختبار تحديد المستوى.');

        return $quiz;
    }

    // ─── GET /instructor/courses/{course}/quizzes ──────────────────────────

    public function index(Request $request, int $course): JsonResponse
    {
        $courseModel = $this->ownedCourse($request, $course);

        $quizzes = Quiz::forCourse($courseModel->id)
            ->withCount('questions')
            ->withCount(['attempts as completed_students_count' => fn ($q) => $q->whereIn('status', \App\Models\QuizAttempt::GRADED_STATUSES)->distinct('user_id')])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $data = $quizzes->map(function (Quiz $quiz) {
            $gradedAttempts = QuizAttempt::where('quiz_id', $quiz->id)->whereIn('status', QuizAttempt::GRADED_STATUSES)->get();

            return [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'status' => $quiz->status,
                'effective_status' => $quiz->effectiveStatus(),
                'opens_at' => $quiz->opens_at?->toIso8601String(),
                'closes_at' => $quiz->closes_at?->toIso8601String(),
                'max_attempts' => $quiz->max_attempts,
                'counts_toward_final_grade' => $quiz->counts_toward_final_grade,
                'is_final_exam' => $quiz->is_final_exam,
                'required' => $quiz->required,
                'weight' => $quiz->weight !== null ? (float) $quiz->weight : null,
                'class_group_id' => $quiz->class_group_id,
                'questions_count' => $quiz->questions_count,
                'total_points' => (int) $quiz->questions()->sum('points'),
                'position' => $quiz->position,
                'completed_students_count' => $gradedAttempts->pluck('user_id')->unique()->count(),
                'average_score' => $gradedAttempts->count() ? round((float) $gradedAttempts->avg('score'), 2) : null,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ─── POST /instructor/courses/{course}/quizzes ─────────────────────────

    public function store(Request $request, int $course): JsonResponse
    {
        $courseModel = $this->ownedCourse($request, $course);

        $data = $this->validateQuiz($request, $courseModel);

        if (! empty($data['is_final_exam'])) {
            $this->assertNoOtherFinalExam($courseModel->id, null);
        }

        $data['course_id'] = $courseModel->id;
        $data['created_by'] = $request->user()->id;
        $data['status'] ??= Quiz::STATUS_DRAFT;
        $data['position'] = Quiz::forCourse($courseModel->id)->max('position') + 1;

        $quiz = Quiz::create($data);

        return response()->json(['success' => true, 'data' => $quiz], 201);
    }

    // ─── GET /instructor/quizzes/{quiz} ─────────────────────────────────────

    public function show(Request $request, Quiz $quiz): JsonResponse
    {
        $this->ownedQuiz($request, $quiz);
        $quiz->load(['questions' => fn ($q) => $q->orderBy('sort_order')]);
        $quiz->questions->each->makeVisibleForAdmin();

        return response()->json(['success' => true, 'data' => $quiz]);
    }

    // ─── PATCH /instructor/quizzes/{quiz} ───────────────────────────────────

    public function update(Request $request, Quiz $quiz): JsonResponse
    {
        $this->ownedQuiz($request, $quiz);
        $courseModel = Course::find($quiz->course_id);

        $data = $this->validateQuiz($request, $courseModel, updating: true);

        if (array_key_exists('is_final_exam', $data) && $data['is_final_exam']) {
            $this->assertNoOtherFinalExam($quiz->course_id, $quiz->id);
        }

        $quiz->update($data);

        return response()->json(['success' => true, 'data' => $quiz->fresh()]);
    }

    // ─── DELETE /instructor/quizzes/{quiz} ──────────────────────────────────

    public function destroy(Request $request, Quiz $quiz): JsonResponse
    {
        $this->ownedQuiz($request, $quiz);

        if (QuizAttempt::where('quiz_id', $quiz->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف اختبار قام طلاب بتقديم إجابات فيه. استخدم الأرشفة بدلاً من ذلك.',
            ], 422);
        }

        $quiz->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف الاختبار.']);
    }

    // ─── Lifecycle actions ───────────────────────────────────────────────────

    public function publish(Request $request, Quiz $quiz): JsonResponse
    {
        $this->ownedQuiz($request, $quiz);
        $quiz->update(['status' => Quiz::STATUS_ACTIVE, 'opens_at' => null, 'closes_at' => $quiz->closes_at, 'published_at' => $quiz->published_at ?? now()]);

        return response()->json(['success' => true, 'message' => 'تم نشر الاختبار.', 'data' => $quiz->fresh()]);
    }

    public function schedule(Request $request, Quiz $quiz): JsonResponse
    {
        $this->ownedQuiz($request, $quiz);
        $data = $request->validate([
            'opens_at' => ['required', 'date'],
            'closes_at' => ['nullable', 'date', 'after:opens_at'],
        ]);

        $quiz->update([
            'status' => Quiz::STATUS_SCHEDULED,
            'opens_at' => $data['opens_at'],
            'closes_at' => $data['closes_at'] ?? null,
            'published_at' => $quiz->published_at ?? now(),
        ]);

        return response()->json(['success' => true, 'message' => 'تمت جدولة الاختبار.', 'data' => $quiz->fresh()]);
    }

    public function close(Request $request, Quiz $quiz): JsonResponse
    {
        $this->ownedQuiz($request, $quiz);
        $quiz->update(['status' => Quiz::STATUS_CLOSED]);

        return response()->json(['success' => true, 'message' => 'تم إيقاف الاختبار.', 'data' => $quiz->fresh()]);
    }

    public function reopen(Request $request, Quiz $quiz): JsonResponse
    {
        $this->ownedQuiz($request, $quiz);
        $quiz->update(['status' => Quiz::STATUS_ACTIVE, 'closes_at' => null]);

        return response()->json(['success' => true, 'message' => 'تمت إعادة فتح الاختبار.', 'data' => $quiz->fresh()]);
    }

    public function archive(Request $request, Quiz $quiz): JsonResponse
    {
        $this->ownedQuiz($request, $quiz);
        $quiz->update(['status' => Quiz::STATUS_ARCHIVED]);

        return response()->json(['success' => true, 'message' => 'تمت أرشفة الاختبار.', 'data' => $quiz->fresh()]);
    }

    public function duplicate(Request $request, Quiz $quiz): JsonResponse
    {
        $this->ownedQuiz($request, $quiz);
        $quiz->load('questions');

        $copy = $quiz->replicate(['status', 'opens_at', 'closes_at', 'published_at']);
        $copy->title = $quiz->title.' (نسخة)';
        $copy->status = Quiz::STATUS_DRAFT;
        $copy->is_final_exam = false; // never silently duplicate the one final-exam flag
        $copy->position = Quiz::forCourse($quiz->course_id)->max('position') + 1;
        $copy->save();

        foreach ($quiz->questions as $question) {
            $copy->questions()->create([
                'question' => $question->question,
                'type' => $question->type,
                'options' => $question->options,
                'correct_answer' => $question->correct_answer,
                'points' => $question->points,
                'sort_order' => $question->sort_order,
            ]);
        }

        return response()->json(['success' => true, 'data' => $copy->fresh('questions')], 201);
    }

    // ─── Questions ───────────────────────────────────────────────────────────

    public function storeQuestion(Request $request, Quiz $quiz): JsonResponse
    {
        $this->ownedQuiz($request, $quiz);

        $data = $request->validate([
            'question' => 'required|string',
            'type' => 'required|in:multiple_choice,true_false,short_answer,multi_select',
            'options' => 'nullable|array',
            'correct_answer' => 'nullable',
            'points' => 'integer|min:0',
            'sort_order' => 'integer',
        ]);

        $data['correct_answer'] = is_array($data['correct_answer'] ?? null)
            ? $data['correct_answer']
            : [$data['correct_answer'] ?? null];

        $question = $quiz->questions()->create($data);

        return response()->json(['success' => true, 'data' => $question->makeVisibleForAdmin()], 201);
    }

    public function updateQuestion(Request $request, Quiz $quiz, QuizQuestion $question): JsonResponse
    {
        $this->ownedQuiz($request, $quiz);
        abort_unless($question->quiz_id === $quiz->id, 404);

        // Once a student has submitted, silently changing points/correct answers
        // would retroactively alter an already-graded attempt without anyone
        // knowing — block it explicitly instead (Phase 14 safe-editing rule).
        if (QuizAttempt::where('quiz_id', $quiz->id)->whereIn('status', QuizAttempt::GRADED_STATUSES)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تعديل سؤال بعد أن قام طلاب بتقديم إجابات على هذا الاختبار.',
            ], 422);
        }

        $data = $request->validate([
            'question' => 'string',
            'type' => 'in:multiple_choice,true_false,short_answer,multi_select',
            'options' => 'nullable|array',
            'correct_answer' => 'nullable',
            'points' => 'integer|min:0',
            'sort_order' => 'integer',
        ]);

        if (array_key_exists('correct_answer', $data)) {
            $data['correct_answer'] = is_array($data['correct_answer']) ? $data['correct_answer'] : [$data['correct_answer']];
        }

        $question->update($data);

        return response()->json(['success' => true, 'data' => $question->fresh()->makeVisibleForAdmin()]);
    }

    public function destroyQuestion(Request $request, Quiz $quiz, QuizQuestion $question): JsonResponse
    {
        $this->ownedQuiz($request, $quiz);
        abort_unless($question->quiz_id === $quiz->id, 404);

        if (QuizAttempt::where('quiz_id', $quiz->id)->whereIn('status', QuizAttempt::GRADED_STATUSES)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف سؤال بعد أن قام طلاب بتقديم إجابات على هذا الاختبار.',
            ], 422);
        }

        $question->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف السؤال.']);
    }

    // ─── GET /instructor/courses/{course}/students/{user}/quiz-breakdown ──

    public function studentBreakdown(Request $request, int $course, int $user, \App\Services\CourseAssessmentService $service): JsonResponse
    {
        $courseModel = $this->ownedCourse($request, $course);

        $enrolled = \App\Models\Registration::where('user_id', $user)
            ->where('course_id', $courseModel->id)
            ->whereIn('status', \App\Enums\RegistrationStatus::activeEnrollment())
            ->exists();
        abort_unless($enrolled, 404, 'الطالب غير مسجل في هذه الدورة.');

        $classGroupId = \App\Models\ClassGroupStudent::where('user_id', $user)
            ->whereIn('status', \App\Models\ClassGroupStudent::ACTIVE_STATUSES)
            ->whereHas('classGroup', fn ($q) => $q->where('course_id', $courseModel->id))
            ->orderByDesc('assigned_at')->orderByDesc('id')
            ->value('class_group_id');

        $breakdown = $service->finalGradeBreakdown($courseModel, $user, $classGroupId);

        return response()->json(['success' => true, 'data' => $breakdown]);
    }

    // ─── Private ────────────────────────────────────────────────────────────

    private function validateQuiz(Request $request, Course $course, bool $updating = false): array
    {
        $rules = [
            'title' => ($updating ? '' : 'required|').'string|max:255',
            'description' => 'nullable|string',
            'lesson_id' => 'nullable|exists:lessons,id',
            'class_group_id' => [
                'nullable',
                Rule::exists('class_groups', 'id')->where('course_id', $course->id),
            ],
            'passing_score' => 'nullable|numeric|min:0|max:100',
            'status' => ['nullable', Rule::in(Quiz::STATUSES)],
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date|after:opens_at',
            'max_attempts' => 'integer|min:1|max:50',
            'counts_toward_final_grade' => 'boolean',
            'is_final_exam' => 'boolean',
            'required' => 'boolean',
            'weight' => 'nullable|numeric|min:0|max:100',
            'shuffle_questions' => 'boolean',
            'shuffle_answers' => 'boolean',
            'show_result_after_submission' => 'boolean',
            'show_correct_answers' => 'boolean',
        ];

        return $request->validate($rules);
    }

    private function assertNoOtherFinalExam(int $courseId, ?int $exceptQuizId): void
    {
        $exists = Quiz::forCourse($courseId)->finalExam()
            ->when($exceptQuizId, fn ($q) => $q->where('id', '!=', $exceptQuizId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'is_final_exam' => 'يوجد بالفعل اختبار نهائي لهذه الدورة. أرشف الاختبار الحالي أولاً قبل تعيين اختبار آخر كاختبار نهائي.',
            ]);
        }
    }
}
