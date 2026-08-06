<?php

namespace App\Http\Controllers;

use App\AI\Services\AiMeetingService;
use App\Jobs\AI\GenerateMeetingSummaryJob;
use App\Models\Meeting;
use App\Models\MeetingAiSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiMeetingController extends Controller
{
    public function __construct(private readonly AiMeetingService $meetingService) {}

    /**
     * POST /api/ai/meetings/{meeting}/summarize
     *
     * Generate (or regenerate) the AI summary for a meeting.
     * Pass `?async=true` to queue the operation.
     */
    public function summarize(Request $request, Meeting $meeting): JsonResponse
    {
        if ($request->boolean('async')) {
            GenerateMeetingSummaryJob::dispatch($meeting->id, $request->user()->id)
                ->onQueue(config('ai.queue', 'default'));

            return response()->json([
                'success' => true,
                'message' => 'Meeting summary is being generated. Check back shortly.',
            ], 202);
        }

        $summary = $this->meetingService->summarizeMeeting($meeting, $request->user()->id);

        return response()->json([
            'success' => true,
            'data'    => $summary,
        ]);
    }

    /**
     * GET /api/ai/meetings/{meeting}/summary
     */
    public function getSummary(Meeting $meeting): JsonResponse
    {
        $summary = MeetingAiSummary::query()->where('meeting_id', $meeting->id)->first();

        if (! $summary) {
            return response()->json(['success' => false, 'message' => 'No AI summary available yet. Use POST /summarize to generate one.'], 404);
        }

        return response()->json(['success' => true, 'data' => $summary]);
    }

    /**
     * POST /api/ai/meetings/{meeting}/action-items
     *
     * Extract (or re-extract) action items from a meeting.
     */
    public function actionItems(Request $request, Meeting $meeting): JsonResponse
    {
        $items = $this->meetingService->extractActionItems($meeting, $request->user()->id);

        return response()->json([
            'success' => true,
            'data'    => ['meeting_id' => $meeting->id, 'action_items' => $items],
        ]);
    }
}
