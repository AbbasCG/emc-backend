<?php

namespace App\AI\Tools;

use App\Models\CalendarEvent;

class GetUpcomingSessionsTool implements AiToolInterface
{
    public function name(): string
    {
        return 'get_upcoming_sessions';
    }

    public function description(): string
    {
        return 'Get upcoming sessions or calendar events for a user. Returns next 5 events.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer', 'description' => 'User ID (defaults to calling user)'],
                'days'    => ['type' => 'integer', 'description' => 'Look ahead days (default 7)'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $args, ?int $userId = null): mixed
    {
        $targetId = (int) ($args['user_id'] ?? $userId ?? 0);
        $days     = min((int) ($args['days'] ?? 7), 30);

        $events = CalendarEvent::query()
            ->where('user_id', $targetId)
            ->where('start_at', '>=', now())
            ->where('start_at', '<=', now()->addDays($days))
            ->orderBy('start_at')
            ->take(5)
            ->get(['id', 'title', 'start_at', 'end_at', 'location', 'meeting_url']);

        return [
            'user_id'   => $targetId,
            'days_ahead'=> $days,
            'count'     => $events->count(),
            'events'    => $events->toArray(),
        ];
    }

    public function isAllowedForRole(string $role): bool
    {
        return true;
    }
}
