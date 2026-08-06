<?php

namespace App\Http\Controllers;

use App\AI\Services\AiContentGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiContentGenerationController extends Controller
{
    public function __construct(private readonly AiContentGenerationService $generator) {}

    /** POST /api/ai/generate/course-outline */
    public function courseOutline(Request $request): JsonResponse
    {
        $data = $request->validate([
            'topic' => 'required|string|max:255',
            'level' => 'sometimes|in:beginner,intermediate,advanced',
        ]);

        return $this->respond(
            fn () => $this->generator->generateCourseOutline($data['topic'], $data['level'] ?? 'beginner', $request->user()?->id),
            'Course outline generated.'
        );
    }

    /** POST /api/ai/generate/workshop */
    public function workshop(Request $request): JsonResponse
    {
        $data = $request->validate([
            'topic' => 'required|string|max:255',
            'hours' => 'sometimes|integer|min:1|max:16',
        ]);

        return $this->respond(
            fn () => $this->generator->generateWorkshopOutline($data['topic'], $data['hours'] ?? 3, $request->user()?->id),
            'Workshop outline generated.'
        );
    }

    /** POST /api/ai/generate/quiz */
    public function quiz(Request $request): JsonResponse
    {
        $data = $request->validate([
            'topic' => 'required|string|max:255',
            'count' => 'sometimes|integer|min:1|max:20',
            'type'  => 'sometimes|in:multiple_choice,true_false,short_answer',
        ]);

        return $this->respond(
            fn () => $this->generator->generateQuizQuestions($data['topic'], $data['count'] ?? 5, $data['type'] ?? 'multiple_choice', $request->user()?->id),
            'Quiz questions generated.'
        );
    }

    /** POST /api/ai/generate/marketing-copy */
    public function marketingCopy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'program'  => 'required|string|max:255',
            'audience' => 'sometimes|string|max:100',
        ]);

        return $this->respond(
            fn () => $this->generator->generateMarketingCopy($data['program'], $data['audience'] ?? 'professionals', $request->user()?->id),
            'Marketing copy generated.'
        );
    }

    /** POST /api/ai/generate/report-summary */
    public function reportSummary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'report_data' => 'required|string|max:8000',
        ]);

        return $this->respond(
            fn () => $this->generator->generateReportSummary($data['report_data'], $request->user()?->id),
            'Report summary generated.'
        );
    }

    /** POST /api/ai/generate/learning-path */
    public function learningPath(Request $request): JsonResponse
    {
        $data = $request->validate([
            'goal'          => 'required|string|max:255',
            'current_level' => 'sometimes|in:beginner,intermediate,advanced',
        ]);

        return $this->respond(
            fn () => $this->generator->generateLearningPath($data['goal'], $data['current_level'] ?? 'beginner', $request->user()?->id),
            'Learning path generated.'
        );
    }

    // -----------------------------------------------------------------------

    private function respond(callable $generator, string $message): JsonResponse
    {
        $content = $generator();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => ['content' => $content],
        ]);
    }
}
