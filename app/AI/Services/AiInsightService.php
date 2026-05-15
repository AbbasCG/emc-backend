<?php

namespace App\AI\Services;

use App\AI\AiManager;
use App\AI\DTOs\AiRequest;
use App\Models\AiInsight;
use App\Models\AiRecommendation;
use App\Models\Registration;
use App\Models\User;

/**
 * Generates AI insights (risk analysis, engagement scores) and recommendations.
 */
class AiInsightService
{
    public function __construct(private readonly AiManager $ai) {}

    /**
     * Analyze platform-wide data and store AI insights.
     */
    public function generatePlatformInsights(?int $userId = null): array
    {
        $stats = $this->collectStats();

        $systemPrompt = 'You are an educational analytics expert. Analyze the platform metrics and return a JSON array of insights. Each insight: {type, title, summary, severity (low/medium/high/critical), score (0-100)}.';

        $request = AiRequest::fromConversation([
            ['role' => 'user', 'content' => "Analyze these platform metrics:\n" . json_encode($stats, JSON_PRETTY_PRINT) . "\n\nReturn only valid JSON array."],
        ], $systemPrompt, ['metadata' => ['type' => 'insights']]);

        $response = $this->ai->chat($request, $userId, 'generate_insights');

        $clean    = preg_replace('/```(?:json)?\s*([\s\S]*?)\s*```/', '$1', trim($response->content));
        $insights = json_decode($clean, true);

        if (! is_array($insights)) {
            return [];
        }

        $created = [];
        foreach ($insights as $insight) {
            $created[] = AiInsight::create([
                'type'         => $insight['type'] ?? 'general',
                'title'        => $insight['title'] ?? 'Insight',
                'summary'      => $insight['summary'] ?? '',
                'severity'     => $insight['severity'] ?? 'low',
                'score'        => $insight['score'] ?? null,
                'metadata'     => ['stats_snapshot' => $stats],
                'generated_at' => now(),
            ]);
        }

        return $created;
    }

    /**
     * Generate course recommendations for a student.
     */
    public function generateStudentRecommendations(User $user): array
    {
        $enrolledIds = Registration::query()
            ->where('user_id', $user->id)
            ->pluck('course_id')
            ->toArray();

        $request = AiRequest::fromConversation([
            ['role' => 'user', 'content' => "Student profile:\n- Name: {$user->name}\n- Enrolled courses: " . count($enrolledIds) . "\n\nSuggest 3 next steps or course recommendations as JSON array: [{type, title, description, priority}]"],
        ], 'You are an educational advisor. Generate personalized recommendations.', [
            'metadata' => ['type' => 'recommendations'],
        ]);

        $response = $this->ai->chat($request, $user->id, 'student_recommendations');

        $clean = preg_replace('/```(?:json)?\s*([\s\S]*?)\s*```/', '$1', trim($response->content));
        $recs  = json_decode($clean, true);

        if (! is_array($recs)) {
            return [];
        }

        // Clear old active recommendations for this user
        AiRecommendation::query()->where('user_id', $user->id)->where('status', 'active')->delete();

        $created = [];
        foreach ($recs as $rec) {
            $created[] = AiRecommendation::create([
                'user_id'      => $user->id,
                'type'         => $rec['type'] ?? 'suggested_course',
                'title'        => $rec['title'] ?? 'Recommendation',
                'description'  => $rec['description'] ?? '',
                'priority'     => $rec['priority'] ?? 'medium',
                'status'       => 'active',
                'generated_at' => now(),
            ]);
        }

        return $created;
    }

    // -----------------------------------------------------------------------

    private function collectStats(): array
    {
        return [
            'total_registrations'  => Registration::query()->count(),
            'active_students'      => Registration::query()->where('status', 'Registered')->count(),
            'this_month_signups'   => Registration::query()->where('created_at', '>=', now()->startOfMonth())->count(),
        ];
    }
}
