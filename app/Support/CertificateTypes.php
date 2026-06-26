<?php

namespace App\Support;

final class CertificateTypes
{
    /** @var list<string> */
    public const ALL = [
        'course_completion',
        'workshop_attendance',
        'summer_camp',
        'learning_track',
        'partner',
        'guest_speaker',
        'volunteer',
        'internship',
        'sponsor',
        'custom',
    ];

    public static function isValid(?string $type): bool
    {
        return $type !== null && $type !== '' && in_array($type, self::ALL, true);
    }
}
