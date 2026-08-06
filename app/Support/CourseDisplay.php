<?php

namespace App\Support;

class CourseDisplay
{
    public static function isOneSession(?string $programType, ?string $sessionFormat): bool
    {
        $pt = strtolower(trim((string) $programType));
        $sf = strtolower(trim((string) $sessionFormat));

        if ($pt === 'one_session' || $pt === 'workshop_one_session') {
            return true;
        }

        if (in_array($sf, ['workshop_single_session', 'workshop_one_session'], true)) {
            return true;
        }

        return str_contains($sf, 'one_session') || str_contains($sf, 'لقاء واحد');
    }

    public static function resolvedDuration(?string $programType, ?string $sessionFormat, ?string $duration): ?string
    {
        if (self::isOneSession($programType, $sessionFormat)) {
            return '1 يوم';
        }

        $trimmed = trim((string) $duration);

        return $trimmed !== '' ? $trimmed : null;
    }

    public static function normalizeSessionFormatInput(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);
        $lower = strtolower($raw);

        if (in_array($lower, ['workshop_single_session', 'workshop_one_session'], true)
            || str_contains($raw, 'لقاء واحد')) {
            return 'workshop_single_session';
        }

        if (in_array($lower, ['online', 'offline', 'hybrid'], true)) {
            return $lower;
        }

        return $raw;
    }

    public static function applyOneSessionDefaults(array $data): array
    {
        $programType = $data['program_type'] ?? null;
        $sessionFormat = $data['session_format'] ?? null;

        if (! self::isOneSession($programType, $sessionFormat)) {
            return $data;
        }

        $data['program_type'] = 'one_session';
        $data['session_format'] = 'workshop_single_session';
        $data['duration'] = '1 يوم';

        return $data;
    }
}
