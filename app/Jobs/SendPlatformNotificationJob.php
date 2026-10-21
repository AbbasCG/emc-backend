<?php

namespace App\Jobs;

use App\Events\PlatformNotificationCreated;
use App\Models\NotificationPreference;
use App\Models\PlatformNotification;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\QuietHoursService;
use App\Services\Sms\SmsNotificationService;
use App\Support\NotificationChannelPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

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

        $ids = $this->excludeUsersWhoDisabledThisType($ids);
        if (empty($ids)) {
            return;
        }

        $entityId = $this->extra['entity_id'] ?? null;
        $idempotencyKey = $this->extra['idempotency_key'] ?? null;

        // The SELECT (dedup check) and INSERT are wrapped in one transaction
        // with a row lock on the matching window, so two workers racing the
        // same recipients/type/entity within the dedup window serialize
        // against each other instead of both passing the SELECT and double
        // inserting. When the caller supplies an explicit idempotency_key
        // (Step 11, opt-in — see NotificationService::send()'s docblock),
        // dedup is instead a deterministic (user_id, type, idempotency_key)
        // lookup, immune to timing entirely, not just narrowed by a window.
        // Callers that omit it keep the original time-window+lock behavior,
        // still a narrowing rather than a full guarantee — documented as a
        // known remaining hardening opportunity for those callers, not
        // silently claimed as solved.
        // Three attempts: two workers sending the SAME de-duplicated notification at once can deadlock on the lock-then-insert below
        // (found by concurrent duplicate Stripe webhooks); the loser retries and then finds the row the winner stored.
        DB::transaction(function () use ($ids, $entityId, $idempotencyKey) {
            $remainingIds = $ids;

            if ($idempotencyKey !== null) {
                $alreadyNotified = PlatformNotification::whereIn('user_id', $ids)
                    ->where('type', $this->type)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->pluck('user_id')
                    ->all();

                if (! empty($alreadyNotified)) {
                    $remainingIds = array_values(array_diff($remainingIds, $alreadyNotified));
                }
            } elseif ($entityId !== null) {
                $windowStart = now()->subSeconds(NotificationService::DEDUP_WINDOW_SECONDS);

                $alreadyNotified = PlatformNotification::whereIn('user_id', $ids)
                    ->where('type', $this->type)
                    ->where('entity_id', $entityId)
                    ->where('created_at', '>=', $windowStart)
                    ->lockForUpdate()
                    ->pluck('user_id')
                    ->all();

                if (! empty($alreadyNotified)) {
                    $remainingIds = array_values(array_diff($remainingIds, $alreadyNotified));
                }
            }

            if (empty($remainingIds)) {
                return;
            }

            $now  = now();
            $base = [
                'type'             => $this->type,
                'title'            => $this->title,
                'message'          => $this->message,
                'entity_type'      => $this->extra['entity_type'] ?? null,
                'entity_id'        => $entityId,
                'idempotency_key'  => $idempotencyKey,
                'action_url'       => $this->extra['action_url']  ?? null,
                'meta_url'         => $this->safeExternalUrl($this->extra['meta_url'] ?? null),
                'icon'             => $this->extra['icon']         ?? 'bell',
                'color'            => $this->extra['color']        ?? 'blue',
                'is_read'          => false,
                'created_by'       => $this->extra['created_by']  ?? null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];

            $rows = array_map(fn ($uid) => array_merge($base, ['user_id' => $uid]), $remainingIds);

            PlatformNotification::insert($rows);

            // Re-fetch the just-inserted rows (same $now, same user set) to get
            // real model instances — needed for the realtime broadcast and the
            // optional external-channel fan-out below, without giving up the
            // single bulk INSERT's performance for large recipient batches.
            $created = PlatformNotification::whereIn('user_id', $remainingIds)
                ->where('type', $this->type)
                ->where('created_at', $now)
                ->get();

            foreach ($created as $notification) {
                broadcast(new PlatformNotificationCreated($notification));
                $this->fanOutToExternalChannels($notification);
            }
        }, 3);
    }

    /**
     * Fan-out to disruptive external channels (push/SMS), governed by the
     * canonical NotificationChannelPolicy (Ticket 8 channel-policy
     * completion pass). Callers may still pass an explicit
     * `$extra['channels']` to override the policy for a specific dispatch
     * (e.g. a future scenario with a genuinely exceptional need) — when
     * absent, the type's registered policy is used, resolved with real
     * timing context (`$extra['starts_at']`/`is_update`) for the handful
     * of scenarios whose policy is context-dependent. Each channel is
     * independently gated by its own NotificationPreference row and,
     * unless urgent (also policy-resolved, overridable via
     * `$extra['urgent']`), delayed — not suppressed — past the
     * recipient's quiet hours.
     */
    private function fanOutToExternalChannels(PlatformNotification $notification): void
    {
        $channels = $this->extra['channels']
            ?? NotificationChannelPolicy::resolveWithContext($this->type, [
                'starts_at' => $this->extra['starts_at'] ?? null,
                'is_update' => $this->extra['is_update'] ?? false,
            ]);

        // in_app is handled by the INSERT above, not this external fan-out.
        $channels = array_values(array_diff($channels, [NotificationChannelPolicy::CHANNEL_IN_APP]));
        if (empty($channels)) {
            return;
        }

        $user = User::find($notification->user_id);
        if (! $user) {
            return;
        }

        $urgent = (bool) ($this->extra['urgent'] ?? NotificationChannelPolicy::isUrgent($this->type));
        $quietHours = app(QuietHoursService::class);
        $delay = (! $urgent && $quietHours->isWithinQuietHours($user)) ? $quietHours->nextAllowedTime($user) : null;

        if (in_array('sms', $channels, true) && $this->channelEnabled($user->id, 'sms') && SmsNotificationService::isValidE164($user->phone)) {
            $job = SendSmsJob::dispatch($user->phone, $this->type, ['title' => $this->title, 'message' => $this->message], $user->id);
            if ($delay) {
                $job->delay($delay);
            }
        }

        if (in_array('push', $channels, true) && $this->channelEnabled($user->id, 'push')) {
            $job = SendPushJob::dispatch($user->id, $this->type, $this->title, $this->message, [
                'url' => $notification->action_url ?? $notification->meta_url,
                'notification_id' => $notification->id,
            ]);
            if ($delay) {
                $job->delay($delay);
            }
        }
    }

    private function channelEnabled(int $userId, string $channel): bool
    {
        return NotificationPreference::isEnabled($userId, $channel, $this->type);
    }

    /**
     * Excludes recipients who have explicitly disabled this notification
     * type on the in-app channel via NotificationPreference. Previously
     * this table was never consulted anywhere in the send pipeline — a
     * user's toggle had no effect. Absence of a preference row means
     * enabled (matches the column's own `default(true)`), so this is
     * additive/opt-out, never opt-in-required.
     */
    private function excludeUsersWhoDisabledThisType(array $ids): array
    {
        $disabledUserIds = NotificationPreference::whereIn('user_id', $ids)
            ->where('channel', NotificationService::IN_APP_CHANNEL)
            ->where('type', $this->type)
            ->where('enabled', false)
            ->pluck('user_id')
            ->all();

        if (empty($disabledUserIds)) {
            return $ids;
        }

        return array_values(array_diff($ids, $disabledUserIds));
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
