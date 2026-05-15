<?php

namespace App\AI\Services;

use App\AI\AiManager;
use App\AI\DTOs\AiRequest;
use App\Models\AiPrompt;

/**
 * AI content generation for courses, workshops, quizzes, and marketing.
 *
 * Each method:
 * 1. Resolves the correct system prompt from ai_prompts table (or fallback).
 * 2. Sends a structured request to the AI provider.
 * 3. Returns the AI response content.
 *
 * All calls are logged automatically via AiManager.
 */
class AiContentGenerationService
{
    public function __construct(private readonly AiManager $ai) {}

    public function generateCourseOutline(string $topic, string $level = 'beginner', ?int $userId = null): string
    {
        return $this->generate('generate_course_outline', [
            ['role' => 'user', 'content' => "Generate a detailed course outline for: \"{$topic}\" (level: {$level}). Include modules, learning objectives, and estimated duration."],
        ], $userId, 'course_outline');
    }

    public function generateWorkshopOutline(string $topic, int $hours = 3, ?int $userId = null): string
    {
        return $this->generate('generate_workshop_outline', [
            ['role' => 'user', 'content' => "Generate a {$hours}-hour workshop outline for: \"{$topic}\". Include agenda, activities, and materials."],
        ], $userId, 'workshop_outline');
    }

    public function generateQuizQuestions(string $topic, int $count = 5, string $type = 'multiple_choice', ?int $userId = null): string
    {
        return $this->generate('generate_quiz_questions', [
            ['role' => 'user', 'content' => "Generate {$count} {$type} quiz questions about: \"{$topic}\". Return JSON array with question, options, and correct answer."],
        ], $userId, 'quiz_questions');
    }

    public function generateMarketingCopy(string $program, string $audience = 'professionals', ?int $userId = null): string
    {
        return $this->generate('generate_marketing_copy', [
            ['role' => 'user', 'content' => "Write compelling marketing copy for \"{$program}\" targeting {$audience}. Include headline, value proposition, and call-to-action."],
        ], $userId, 'marketing_copy');
    }

    public function generateReportSummary(string $reportData, ?int $userId = null): string
    {
        return $this->generate('generate_report_summary', [
            ['role' => 'user', 'content' => "Summarize the following report data in 3-5 clear paragraphs with key insights:\n\n{$reportData}"],
        ], $userId, 'report_summary');
    }

    public function generateCertificateText(string $recipientName, string $courseName, ?int $userId = null): string
    {
        return $this->generate('generate_certificate_text', [
            ['role' => 'user', 'content' => "Write a formal certificate completion text for {$recipientName} who completed \"{$courseName}\"."],
        ], $userId, 'certificate_text');
    }

    public function generateLearningPath(string $goal, string $currentLevel = 'beginner', ?int $userId = null): string
    {
        return $this->generate('generate_learning_path', [
            ['role' => 'user', 'content' => "Design a learning path for goal: \"{$goal}\" (current level: {$currentLevel}). Include recommended tracks, courses, and timeline."],
        ], $userId, 'learning_path');
    }

    // -----------------------------------------------------------------------

    private function generate(string $promptKey, array $messages, ?int $userId, string $actionType): string
    {
        $systemPrompt = AiPrompt::render($promptKey)
            ?? $this->fallbackPrompt($actionType);

        $request  = AiRequest::fromConversation($messages, $systemPrompt, [
            'metadata' => ['type' => $actionType],
        ]);
        $response = $this->ai->chat($request, $userId, "generate_{$actionType}");

        return $response->success
            ? ($response->content ?: '*(no content generated)*')
            : "Generation failed: {$response->error}";
    }

    private function fallbackPrompt(string $type): string
    {
        return match ($type) {
            'course_outline'    => 'You are an expert curriculum designer. Create structured, practical course outlines.',
            'workshop_outline'  => 'You are an expert workshop facilitator. Create engaging workshop agendas.',
            'quiz_questions'    => 'You are an expert assessment designer. Create clear, valid quiz questions.',
            'marketing_copy'    => 'You are an expert educational marketing copywriter. Write compelling, professional copy.',
            'report_summary'    => 'You are an expert data analyst. Summarize reports clearly and concisely.',
            'certificate_text'  => 'You are a formal document writer. Write dignified certificate text.',
            'learning_path'     => 'You are an expert learning consultant. Design structured, achievable learning paths.',
            default             => 'You are a helpful EMC platform assistant. Provide clear, professional responses.',
        };
    }
}
