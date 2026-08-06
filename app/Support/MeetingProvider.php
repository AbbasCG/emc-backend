<?php

namespace App\Support;

/**
 * Which conferencing provider a stored `meeting_link` belongs to.
 *
 * Every provider currently reduces to "a URL the instructor/student open" —
 * none of them are live-integrated (no Zoom/Google Meet/Teams API calls are
 * made to create rooms). This enum exists so the UI can label/icon the link
 * correctly today, and so a future provider (e.g. an actual Zoom API
 * integration that creates the room server-side) can plug in by populating
 * the same `meeting_link`/`meeting_provider` columns without a schema
 * change or new booking-flow branch.
 */
enum MeetingProvider: string
{
    case CUSTOM_URL = 'custom_url';
    case GOOGLE_MEET = 'google_meet';
    case ZOOM = 'zoom';
    case TEAMS = 'teams';

    public function label(): string
    {
        return match ($this) {
            self::CUSTOM_URL => 'رابط مخصص',
            self::GOOGLE_MEET => 'Google Meet',
            self::ZOOM => 'Zoom',
            self::TEAMS => 'Microsoft Teams',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
