<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Jobs\SendWhatsAppJob;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\User;

/**
 * Central dispatcher for all notification channels.
 *
 * Respects the user's notification preferences.
 * Queues delivery — never blocks the request.
 *
 * Usage:
 *   app(NotificationDispatcher::class)->send($user, 'certificate_issued', [
 *       'name'   => $user->name,
 *       'course' => $course->title,
 *   ]);
 */
class NotificationDispatcher
{
    /**
     * Send a notification on all enabled channels for this user and type.
     *
     * @param  User                   $user
     * @param  string                 $type       e.g. 'certificate_issued'
     * @param  array<string, string>  $variables  Template substitution variables
     * @param  array<string>          $channels   Override channels (default: all)
     */
    public function send(User $user, string $type, array $variables = [], array $channels = []): void
    {
        $defaultChannels = ['in_app', 'email'];

        // Add WhatsApp if user has a phone number
        if ($user->phone ?? null) {
            $defaultChannels[] = 'whatsapp';
        }

        $channels = $channels ?: $defaultChannels;

        foreach ($channels as $channel) {
            if (! NotificationPreference::isEnabled($user->id, $channel, $type)) {
                continue;
            }

            $this->dispatchChannel($user, $type, $channel, $variables);
        }
    }

    private function dispatchChannel(User $user, string $type, string $channel, array $variables): void
    {
        match ($channel) {
            'in_app' => $this->dispatchInApp($user, $type, $variables),
            'email'  => $this->dispatchEmail($user, $type, $variables),
            'whatsapp' => $this->dispatchWhatsApp($user, $type, $variables),
            default  => null,
        };
    }

    private function dispatchInApp(User $user, string $type, array $variables): void
    {
        $rendered = NotificationTemplate::render($type, 'in_app', $variables)
            ?? ['subject' => null, 'content' => $type];

        // Write directly to the notifications table (fast, no queue needed for in-app)
        $user->notifications()->create([
            'id'              => \Illuminate\Support\Str::uuid(),
            'type'            => $type,
            'notifiable_type' => User::class,
            'notifiable_id'   => $user->id,
            'data'            => [
                'type'    => $type,
                'title'   => $rendered['subject'] ?? $type,
                'message' => $rendered['content'],
            ],
        ]);
    }

    private function dispatchEmail(User $user, string $type, array $variables): void
    {
        $rendered = NotificationTemplate::render($type, 'email', $variables);
        if (! $rendered) {
            return; // No email template configured
        }

        SendEmailJob::dispatch(
            to: $user->email,
            subject: $rendered['subject'] ?? $type,
            body: $rendered['content'],
            isHtml: true,
        );
    }

    private function dispatchWhatsApp(User $user, string $type, array $variables): void
    {
        $phone = $user->phone ?? null;
        if (! $phone) {
            return;
        }

        SendWhatsAppJob::dispatch(
            to: $phone,
            type: $type,
            variables: $variables,
            userId: $user->id,
        );
    }
}
