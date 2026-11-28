<?php

namespace App\Services;

use App\Http\Controllers\Admin\EmailSettingsController;
use App\Models\EmailSetting;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\EmailNotificationTypes;

/**
 * A person's email preferences: which types they can choose for, what they chose, and what actually happens (and why), computed with the
 * SAME decision the sender uses (EmailDeliveryPolicy), so the page cannot promise what the sender will not do.
 *
 * Three states per type, stored in notification_preferences (channel = email, type = the email key):
 *   follow    NO row: the type follows the central setting
 *   enabled   a row with enabled = true
 *   disabled  a row with enabled = false
 * A saved choice is never deleted by anything but the person: it survives a role change (the type just stops being listed while they are
 * not eligible) and a central pause (it applies again when the pause is lifted).
 */
class EmailPreferenceService
{
    public const FOLLOW = 'follow';

    public const ENABLED = 'enabled';

    public const DISABLED = 'disabled';

    public const MODES = [self::FOLLOW, self::ENABLED, self::DISABLED];

    /**
     * The types this person can choose for (eligible by role / permission, no duplicates however many roles qualify them).
     *
     * @return array<int, array<string, mixed>>
     */
    public function typesFor(User $user): array
    {
        $keys = EmailNotificationTypes::eligibleKeysFor($user);
        $saved = NotificationPreference::query()
            ->where('user_id', $user->id)->where('channel', 'email')->whereIn('type', $keys)
            ->get()->keyBy('type');
        $central = EmailSetting::query()->whereIn('key', $keys)->get()->keyBy('key');

        $out = [];
        foreach ($keys as $key) {
            $meta = EmailSettingsController::LABELS[$key];
            $row = $saved->get($key);
            $setting = $central->get($key);
            $decision = EmailDeliveryPolicy::decide($key, $user);

            $out[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'category' => $meta['category'],
                'preference' => $row === null ? self::FOLLOW : ($row->enabled ? self::ENABLED : self::DISABLED),
                // A choice saved before this page existed (see the migration): kept exactly as saved, shown as such.
                'legacy' => $row !== null && $row->source === 'legacy',
                'central' => [
                    'default_enabled' => $setting === null ? true : (bool) $setting->default_enabled,
                    'paused' => $setting !== null && ($setting->paused || ! $setting->enabled),
                ],
                'effective' => [
                    'enabled' => $decision['send'],
                    'source' => $decision['source'],
                    'source_label' => EmailDeliveryPolicy::SOURCE_LABELS[$decision['source']] ?? '',
                ],
            ];
        }

        return $out;
    }

    /**
     * Save choices for THIS user only. Nothing is written unless every entry is valid (no half-applied save).
     *
     * @param  array<string, string>  $choices  key => follow|enabled|disabled
     * @return array<string, array<int, string>>  errors keyed by "preferences.{key}" (empty = saved)
     */
    public function save(User $user, array $choices): array
    {
        $errors = [];
        foreach ($choices as $key => $mode) {
            $key = (string) $key;
            // ONE message for an unknown, a mandatory and a not-available type: the answer must not reveal which type names exist or
            // which are mandatory to someone who may not choose for them.
            if (! EmailNotificationTypes::exists($key)
                || EmailNotificationTypes::isMandatory($key)
                || ! EmailNotificationTypes::isUserConfigurable($key)
                || ! EmailNotificationTypes::isEligible($user, $key)) {
                $errors["preferences.{$key}"] = ['هذا النوع غير متاح لحسابك.'];
            } elseif (! in_array($mode, self::MODES, true)) {
                $errors["preferences.{$key}"] = ['اختيار غير صالح.'];
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        foreach ($choices as $key => $mode) {
            $where = ['user_id' => $user->id, 'channel' => 'email', 'type' => (string) $key];
            if ($mode === self::FOLLOW) {
                NotificationPreference::query()->where($where)->delete();

                continue;
            }
            // A deliberate choice: no longer a legacy row.
            NotificationPreference::updateOrCreate($where, ['enabled' => $mode === self::ENABLED, 'source' => null]);
        }

        return [];
    }
}
