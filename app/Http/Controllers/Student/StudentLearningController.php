<?php

namespace App\Http\Controllers\Student;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseModuleResource;
use App\Http\Resources\LessonResource;
use App\Http\Resources\LmsCourseMaterialResource;
use App\Http\Resources\QuizResource;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Registration;
use App\Services\QuizScoringService;
use App\Services\StudentModuleProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentLearningController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $user          = $request->user();
        $registrations = Registration::with(['course.modules.courseSessions'])
            ->where('user_id', $user->id)
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $registrations->map(fn ($r) => [
                'registration_id' => $r->id,
                'course'          => $r->course ? [
                    'id'             => $r->course->id,
                    'title'          => $r->course->title,
                    'modules_count'  => $r->course->modules->count(),
                    'sessions_count' => $r->course->modules->sum(fn ($m) => $m->courseSessions->count()),
                ] : null,
            ]),
        ]);
    }

    public function modules(Request $request, int $courseId, StudentModuleProgressService $progressService): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $enrolled = Registration::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->exists();

        abort_if(! $enrolled, 403, 'You are not enrolled in this course.');

        $modules = CourseModule::with(['courseSessions.materials', 'courseSessions.assignments', 'lessons'])
            ->where('course_id', $courseId)
            // Show modules that are not explicitly deactivated (null means not yet set)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))
            ->whereNotIn('status', \App\Support\CourseContentStatus::HIDDEN_FROM_STUDENTS)
            ->orderBy('sort_order')
            ->get();

        $modulesData = CourseModuleResource::collection($modules)->resolve();
        $progressByModuleId = collect($progressService->computeForModules($modules, $user))->keyBy('id');
        foreach ($modulesData as &$moduleRow) {
            $moduleRow['progress'] = $progressByModuleId->get($moduleRow['id']);
        }
        unset($moduleRow);

        $course = \App\Models\Course::query()->with('modules')->find($courseId);
        $courseProgress = $course ? $progressService->computeForCourse($course, $user) : null;

        return response()->json([
            'success'         => true,
            'data'            => $modulesData,
            'course_progress' => $courseProgress,
        ]);
    }

    public function lesson(Request $request, Lesson $lesson, StudentModuleProgressService $progressService): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $lesson->loadMissing('module.course');
        $courseId = $lesson->module->course_id;

        $enrolled = Registration::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->exists();

        abort_if(! $enrolled, 403, 'You are not enrolled in this course.');
        abort_if(! $lesson->isVisibleToStudents() || ! $lesson->module?->isVisibleToStudents(), 404);

        $isCompleted = LessonCompletion::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->exists();

        $siblingLessons = Lesson::query()
            ->where('module_id', $lesson->module_id)
            ->where('status', \App\Support\CourseContentStatus::ACTIVE)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->values();
        $position = $siblingLessons->search($lesson->id);
        $previousLessonId = $position !== false && $position > 0 ? $siblingLessons[$position - 1] : null;
        $nextLessonId = $position !== false && $position < $siblingLessons->count() - 1 ? $siblingLessons[$position + 1] : null;

        $eligibility = app(\App\Services\LearningContentEligibilityService::class);
        $materials = $lesson->materials()
            ->where('is_visible', true)
            ->get()
            ->filter(fn ($material) => $eligibility->studentCanViewMaterial($user, $material))
            ->values();

        $module  = $lesson->module;
        $modulesProgress = $progressService->computeForModule($module, $user);
        $courseProgress  = $progressService->computeForCourse($module->course, $user);

        // Additive only: every field the pre-existing LessonResource returned is
        // preserved at the same top-level `data` shape; new fields are appended
        // rather than nesting under a new `lesson` key, so existing consumers of
        // this endpoint's flat `data.title`/`data.status`/etc. do not break.
        $lessonData = (new LessonResource($lesson->load('quizzes')))->resolve();
        $lessonData['is_completed']       = $isCompleted;
        $lessonData['module_id']          = $lesson->module_id;
        $lessonData['previous_lesson_id'] = $previousLessonId;
        $lessonData['next_lesson_id']     = $nextLessonId;
        $lessonData['materials']          = LmsCourseMaterialResource::collection($materials)->resolve();

        return response()->json([
            'success'         => true,
            'data'            => $lessonData,
            'module_progress' => $modulesProgress,
            'course_progress' => $courseProgress,
        ]);
    }

    public function submitQuiz(Request $request, Quiz $quiz, QuizScoringService $scorer): \Illuminate\Http\JsonResponse
    {
        // Guard: quiz must belong to a course the student is enrolled in.
        $courseId = $quiz->course_id ?? $quiz->lesson?->module?->course_id;
        if ($courseId) {
            $enrolled = Registration::where('user_id', $request->user()->id)
                ->where('course_id', $courseId)
                ->whereIn('status', RegistrationStatus::activeEnrollment())
                ->exists();

            abort_unless($enrolled, 403, 'You are not enrolled in the course this quiz belongs to.');
        }

        $data = $request->validate([
            'answers' => 'required|array',
        ]);

        $result = $scorer->score($quiz, $data['answers']);

        $attempt = DB::transaction(function () use ($quiz, $request, $data, $result) {
            return QuizAttempt::create([
                'quiz_id'      => $quiz->id,
                'user_id'      => $request->user()->id,
                'score'        => $result['score'],
                'answers'      => $data['answers'],
                'status'       => $result['passed'] === true ? 'passed' : ($result['passed'] === false ? 'failed' : 'in_progress'),
                'submitted_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'attempt_id'    => $attempt->id,
                'score'         => $result['score'],
                'passed'        => $result['passed'],
                'points_earned' => $result['points_earned'],
                'total_points'  => $result['total_points'],
            ],
        ]);
    }
}
