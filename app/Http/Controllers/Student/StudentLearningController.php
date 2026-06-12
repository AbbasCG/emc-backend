<?php

namespace App\Http\Controllers\Student;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseModuleResource;
use App\Http\Resources\LessonResource;
use App\Http\Resources\QuizResource;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Registration;
use App\Services\QuizScoringService;
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

    public function modules(Request $request, int $courseId): \Illuminate\Http\JsonResponse
    {
        $enrolled = Registration::where('user_id', $request->user()->id)
            ->where('course_id', $courseId)
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->exists();

        abort_if(! $enrolled, 403, 'You are not enrolled in this course.');

        $modules = CourseModule::with(['courseSessions.materials', 'courseSessions.assignments'])
            ->where('course_id', $courseId)
            // Show modules that are not explicitly deactivated (null means not yet set)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))
            ->whereNotIn('status', ['inactive', 'draft'])
            ->orderBy('sort_order')
            ->get();

        return response()->json(['success' => true, 'data' => CourseModuleResource::collection($modules)]);
    }

    public function lesson(Request $request, Lesson $lesson): \Illuminate\Http\JsonResponse
    {
        $courseId = $lesson->module->course_id;

        $enrolled = Registration::where('user_id', $request->user()->id)
            ->where('course_id', $courseId)
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->exists();

        abort_if(! $enrolled, 403, 'You are not enrolled in this course.');
        abort_if($lesson->status !== 'active', 404);

        return response()->json([
            'success' => true,
            'data'    => new LessonResource($lesson->load('quizzes')),
        ]);
    }

    public function submitQuiz(Request $request, Quiz $quiz, QuizScoringService $scorer): \Illuminate\Http\JsonResponse
    {
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
