<?php

namespace App\Jobs;

use App\Models\PlatformNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Performs the actual PlatformNotification insert for NotificationService::send().
 * Moved off the request thread — a single controller action (e.g. bulk grading,
 * class assignment) can fan out to dozens of recipients, and this insert
 * (plus its dedup lookup) previously ran synchronously inline every time.
 * QUEUE_CONNECTION=sync in tests runs this immediately, so behavior and
 * assertions on PlatformNotification rows are unaffected there.
 */
class SendPlatformNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly array $userIds,
        public readonly string $type,
        public readonly string $title,
        public readonly string $message,
        public readonly array $extra = [],
    ) {}

    public function handle(): void
    {
        $ids = array_values(array_unique(array_filter($this->userIds)));
        if (empty($ids)) {
            return;
        }

        $entityId    = $this->extra['entity_id'] ?? null;
        $windowStart = now()->subSeconds(10);

        if ($entityId !== null) {
            $alreadyNotified = PlatformNotification::whereIn('user_id', $ids)
                ->where('type', $this->type)
                ->where('entity_id', $entityId)
                ->where('created_at', '>=', $windowStart)
                ->pluck('user_id')
                ->all();

            if (! empty($alreadyNotified)) {
                $ids = array_values(array_diff($ids, $alreadyNotified));
            }
        }

        if (empty($ids)) {
            return;
        }

        $now  = now();
        $base = [
            'type'        => $this->type,
            'title'       => $this->title,
            'message'     => $this->message,
            'entity_type' => $this->extra['entity_type'] ?? null,
            'entity_id'   => $entityId,
            'action_url'  => $this->extra['action_url']  ?? null,
            'meta_url'    => $this->safeExternalUrl($this->extra['meta_url'] ?? null),
            'icon'        => $this->extra['icon']         ?? 'bell',
            'color'       => $this->extra['color']        ?? 'blue',
            'is_read'     => false,
            'created_by'  => $this->extra['created_by']  ?? null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        $rows = array_map(fn ($uid) => array_merge($base, ['user_id' => $uid]), $ids);

        PlatformNotification::insert($rows);
    }

    private function safeExternalUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $parts = parse_url($url);
        if (! $parts || ! isset($parts['scheme']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }
}
