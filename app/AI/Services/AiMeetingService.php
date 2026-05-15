<?php

namespace App\AI\Services;

use App\AI\AiManager;
use App\AI\DTOs\AiRequest;
use App\Models\Meeting;
use App\Models\MeetingAiSummary;

/**
 * AI meeting intelligence: summarization, decision extraction, action items.
 */
class AiMeetingService
{
    public function __construct(private readonly AiManager $ai) {}

    /**
     * Generate a full AI summary for a meeting.
     * Stores result in meeting_ai_summaries.
     */
    public function summarizeMeeting(Meeting $meeting, ?int $userId = null): MeetingAiSummary
    {
        $notes   = $meeting->notes ?? $meeting->description ?? '(No notes provided)';
        $title   = $meeting->title ?? "Meeting #{$meeting->id}";

        $systemPrompt = 'You are an expert meeting analyst. Analyze meeting content and return a JSON object with keys: summary (string), decisions (array), action_items (array of {task, assignee}), risks (array), blockers (array).';

        $request = AiRequest::fromConversation([
            ['role' => 'user', 'content' => "Analyze this meeting:\n\nTitle: {$title}\n\nNotes:\n{$notes}\n\nReturn only valid JSON."],
        ], $systemPrompt, ['metadata' => ['type' => 'meeting_summary']]);

        $response = $this->ai->chat($request, $userId, 'meeting_summary');

        $parsed = $this->parseJson($response->content, [
            'summary'      => $response->content,
            'decisions'    => [],
            'action_items' => [],
            'risks'        => [],
            'blockers'     => [],
        ]);

        // Upsert — regenerate replaces previous summary
        $summary = MeetingAiSummary::updateOrCreate(
            ['meeting_id' => $meeting->id],
            [
                'summary'      => $parsed['summary'] ?? $response->content,
                'decisions'    => $parsed['decisions'] ?? [],
                'action_items' => $parsed['action_items'] ?? [],
                'risks'        => $parsed['risks'] ?? [],
                'blockers'     => $parsed['blockers'] ?? [],
                'model'        => $response->model,
                'token_usage'  => $response->totalTokens(),
            ],
        );

        return $summary;
    }

    /**
     * Extract only action items from a meeting.
     */
    public function extractActionItems(Meeting $meeting, ?int $userId = null): array
    {
        $summary = MeetingAiSummary::query()->where('meeting_id', $meeting->id)->first();

        if (! $summary) {
            $summary = $this->summarizeMeeting($meeting, $userId);
        }

        return $summary->action_items ?? [];
    }

    // -----------------------------------------------------------------------

    private function parseJson(string $content, array $default): array
    {
        // Strip markdown code fences if present
        $clean = preg_replace('/```(?:json)?\s*([\s\S]*?)\s*```/', '$1', trim($content));
        $data  = json_decode($clean, true);

        return is_array($data) ? $data : $default;
    }
}
