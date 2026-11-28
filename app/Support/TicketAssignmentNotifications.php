<?php

namespace App\Support;

use App\Models\EmailSetting;
use App\Models\NotificationChannelSetting;
use App\Models\NotificationPreference;

/**
 * The two "a ticket was assigned to you" notifications and how each of their
 * channels is decided. One place, so the sender, the admin settings and the
 * user's preference page can never disagree.
 *
 *   operations_ticket_assigned  App\Models\Ticket (reports / suggestions, TicketAssignmentObserver)
 *   ticket_assigned             App\Models\SupportTicket (support desk, SupportTicketController) — the
 *                               original key, kept so existing in-app rows and links stay as they are
 *
 * A channel reaches the assignee only when BOTH are true:
 *   1. the administrator has the channel on for that type
 *        email  → EmailSetting (the existing admin email switch, same key)
 *        in_app → NotificationChannelSetting
 *      A missing row means "on", except where a migration wrote an explicit value.
 *   2. the assignee has not turned it off (NotificationPreference; no row = follows the default, on).
 *
 * The two decisions are stored separately, so an administrator turning a channel off never erases
 * what the user chose: it applies again as soon as the channel is turned back on.
 */
final class TicketAssignmentNotifications
{
    public const OPERATIONS = 'operations_ticket_assigned';

    public const SUPPORT = 'ticket_assigned';

    public const EMAIL = 'email';

    public const IN_APP = 'in_app';

    public const CHANNELS = [self::EMAIL, self::IN_APP];

    public const TYPES = [
        self::OPERATIONS => [
            'label' => 'إسناد تذكرة العمليات',
            'description' => 'عند إسناد بلاغ أو مقترح إليك لمراجعته وقبوله أو الاعتذار عنه.',
        ],
        self::SUPPORT => [
            'label' => 'إسناد تذكرة الدعم الفني',
            'description' => 'عند تعيين تذكرة دعم فني إليك لمتابعتها والرد على صاحبها.',
        ],
    ];

    public const CHANNEL_LABELS = [
        self::EMAIL => 'البريد الإلكتروني',
        self::IN_APP => 'داخل المنصة',
    ];

    public static function isType(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    /** The administrator's switch for this type and channel. */
    public static function adminEnabled(string $type, string $channel): bool
    {
        return $channel === self::EMAIL
            ? EmailSetting::isEnabled($type)
            : NotificationChannelSetting::isEnabled($type, $channel);
    }

    /**
     * The user's own choice (no saved row = follows the default, which is on). This is the compatibility view of the previous
     * assignment page; what is actually SENT is decided by App\Services\EmailDeliveryPolicy (central pause, personal choice,
     * central default), the same rule every email follows.
     */
    public static function userEnabled(int $userId, string $type, string $channel): bool
    {
        return NotificationPreference::isEnabled($userId, $channel, $type);
    }

    /** Whether the channel actually reaches this user right now. */
    public static function delivers(int $userId, string $type, string $channel): bool
    {
        return self::adminEnabled($type, $channel) && self::userEnabled($userId, $type, $channel);
    }

    /**
     * The preference page's view of one user: per type and channel, what they chose, whether that
     * choice was saved or is the default, and whether the administrator has the channel available.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function preferencesFor(int $userId): array
    {
        $saved = NotificationPreference::query()
            ->where('user_id', $userId)
            ->whereIn('type', array_keys(self::TYPES))
            ->whereIn('channel', self::CHANNELS)
            ->get()
            ->keyBy(fn (NotificationPreference $p) => "{$p->type}:{$p->channel}");

        $out = [];
        foreach (self::TYPES as $type => $meta) {
            $channels = [];
            foreach (self::CHANNELS as $channel) {
                $row = $saved->get("{$type}:{$channel}");
                $channels[$channel] = [
                    'enabled' => $row ? (bool) $row->enabled : true,
                    'customized' => $row !== null,
                    'available' => self::adminEnabled($type, $channel),
                ];
            }
            $out[] = ['type' => $type, 'label' => $meta['label'], 'description' => $meta['description'], 'channels' => $channels];
        }

        return $out;
    }

    /**
     * The admin settings' view: per type and channel, whether the administrator has it on.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function adminSettings(): array
    {
        $out = [];
        foreach (self::TYPES as $type => $meta) {
            $channels = [];
            foreach (self::CHANNELS as $channel) {
                $channels[$channel] = ['enabled' => self::adminEnabled($type, $channel)];
            }
            $out[] = ['type' => $type, 'label' => $meta['label'], 'description' => $meta['description'], 'channels' => $channels];
        }

        return $out;
    }

    public static function setAdmin(string $type, string $channel, bool $enabled): void
    {
        if ($channel === self::EMAIL) {
            // The previous page had ONE email switch per type: on = it flows (unpaused AND on by default), off = it stops (paused; the
            // central default is left as it was). The new email settings page sets the two controls independently.
            EmailSetting::saveControls($type, ($enabled && EmailSetting::isPaused($type)) ? true : null, ! $enabled);

            return;
        }

        NotificationChannelSetting::updateOrCreate(['type' => $type, 'channel' => $channel], ['enabled' => $enabled]);
    }
}
