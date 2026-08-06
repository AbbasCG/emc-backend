<?php

namespace App\Services;

use App\Models\AttendanceSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Ticket 6: canonical source for every configurable attendance threshold.
 * When no row exists yet (fresh install, or before the first admin save),
 * returns exactly the hardcoded defaults this behavior already used
 * (70% / 3-in-a-row / 15 late minutes / etc.) — legacy behavior is
 * preserved bit-for-bit, not just "similar," until an admin actually saves
 * a settings row.
 *
 * `certificate_attendance_percentage` is stored and readable here for
 * admin visibility/future use, but is deliberately NOT wired into
 * CertificateEligibilityService this pass — that service has its own,
 * independently-relied-upon hardcoded threshold, and silently changing
 * certificate issuance eligibility is explicitly out of this ticket's
 * safety margin ("do not silently change certificate eligibility rules").
 */
class AttendanceSettingsService
{
    private const CACHE_KEY = 'attendance_settings';

    public const DEFAULTS = [
        'late_threshold_minutes' => 15,
        'auto_absent_after_minutes' => 30,
        'minimum_attendance_percentage' => 70,
        'at_risk_percentage' => 70,
        'repeated_absence_threshold' => 3,
        'low_attendance_notification_threshold' => 70,
        'certificate_attendance_percentage' => 70,
    ];

    /** @return array<string, int> */
    public function current(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $row = AttendanceSettings::query()->first();
            if (! $row) {
                return self::DEFAULTS;
            }

            return array_merge(self::DEFAULTS, $row->only(array_keys(self::DEFAULTS)));
        });
    }

    public function get(string $key): int
    {
        return (int) ($this->current()[$key] ?? self::DEFAULTS[$key] ?? 0);
    }

    /** @param array<string, int> $data */
    public function update(array $data): array
    {
        $row = AttendanceSettings::query()->first() ?? new AttendanceSettings();
        $row->fill(array_intersect_key($data, self::DEFAULTS));
        $row->save();

        Cache::forget(self::CACHE_KEY);

        return $this->current();
    }
}
