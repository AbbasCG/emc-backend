<?php

namespace App\Services\Push;

use App\Models\NotificationLog;
use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Ticket 8 completion pass — Web Push (Step 6).
 *
 * Evidence-based provider choice: the audit found zero existing Firebase/FCM
 * config, no `google-services.json`/service-account credentials, and no
 * mobile app in this repo to justify FCM. Standard Web Push (RFC 8030,
 * VAPID) works across Chrome/Firefox/Edge without a third-party account —
 * the smallest real infrastructure that satisfies "a real push
 * architecture" for this web-only platform. Disabled by default: with no
 * VAPID keys configured, `send()` is a safe no-op (logged, not silently
 * dropped) rather than a fatal error, matching the "safe disabled-by-default
 * configuration" requirement.
 */
class WebPushNotificationService
{
    public function isConfigured(): bool
    {
        return (bool) config('services.vapid.public_key') && (bool) config('services.vapid.private_key');
    }

    /**
     * Send to every subscription this user has registered. Invalid/expired
     * subscriptions (410 Gone / 404, per the Web Push spec) are deleted —
     * never left to silently retry forever.
     */
    public function sendToUser(int $userId, string $type, string $title, string $body, array $data = []): bool
    {
        if (! $this->isConfigured()) {
            NotificationLog::create([
                'user_id' => $userId, 'channel' => 'push', 'type' => $type, 'recipient' => '',
                'status' => 'failed', 'error_message' => 'Push provider not configured (no VAPID keys).',
            ]);

            return false;
        }

        $subscriptions = PushSubscription::where('user_id', $userId)->get();
        if ($subscriptions->isEmpty()) {
            return false;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject'    => config('services.vapid.subject', 'mailto:support@example.com'),
                'publicKey'  => config('services.vapid.public_key'),
                'privateKey' => config('services.vapid.private_key'),
            ],
        ]);

        $payload = json_encode(['type' => $type, 'title' => $title, 'body' => $body, 'data' => $data]);
        $anySuccess = false;

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys'     => ['p256dh' => $sub->p256dh_key, 'auth' => $sub->auth_token],
                ]),
                $payload,
            );
        }

        foreach ($webPush->flush() as $report) {
            $matched = $subscriptions->first(fn (PushSubscription $s) => $s->endpoint === $report->getEndpoint());

            NotificationLog::create([
                'user_id' => $userId, 'channel' => 'push', 'type' => $type,
                'recipient'     => $report->getEndpoint(),
                'status'        => $report->isSuccess() ? 'sent' : 'failed',
                'error_message' => $report->isSuccess() ? null : $report->getReason(),
                'sent_at'       => $report->isSuccess() ? now() : null,
            ]);

            if ($report->isSuccess()) {
                $anySuccess = true;
                $matched?->update(['last_used_at' => now()]);
            } elseif ($report->isSubscriptionExpired() && $matched) {
                $matched->delete();
            }
        }

        return $anySuccess;
    }
}
