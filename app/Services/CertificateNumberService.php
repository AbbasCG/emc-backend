<?php

namespace App\Services;

use App\Models\Certificate;
use Illuminate\Support\Facades\DB;

class CertificateNumberService
{
    /**
     * Generate a unique certificate number.
     * Format: {prefix}-WS{workshopNum}-{year}-{sequence}
     * Examples: EMC-WS1-2026-0001, EMC-CR-2026-0042
     */
    public function generate(
        string $prefix = 'EMC',
        ?int $workshopNumber = null,
        ?int $year = null,
    ): string {
        $year ??= now()->year;
        $segment = $workshopNumber ? "WS{$workshopNumber}" : 'CR';

        return DB::transaction(function () use ($prefix, $segment, $year) {
            $pattern = "{$prefix}-{$segment}-{$year}-%";

            $lastSeq = Certificate::where('certificate_code', 'like', $pattern)
                ->lockForUpdate()
                ->max(DB::raw("CAST(SUBSTRING_INDEX(certificate_code, '-', -1) AS UNSIGNED)"));

            $nextSeq = ($lastSeq ?? 0) + 1;
            $code    = sprintf('%s-%s-%d-%04d', $prefix, $segment, $year, $nextSeq);

            // Collision guard (very unlikely, but safe)
            while (Certificate::where('certificate_code', $code)->exists()) {
                $nextSeq++;
                $code = sprintf('%s-%s-%d-%04d', $prefix, $segment, $year, $nextSeq);
            }

            return $code;
        });
    }

    /**
     * Ordinal workshop number in Arabic.
     */
    public static function arabicOrdinal(int $n): string
    {
        $map = [
            1 => 'الأولى',   2 => 'الثانية',  3 => 'الثالثة',
            4 => 'الرابعة',  5 => 'الخامسة',  6 => 'السادسة',
            7 => 'السابعة',  8 => 'الثامنة',  9 => 'التاسعة',
            10 => 'العاشرة', 11 => 'الحادية عشرة', 12 => 'الثانية عشرة',
        ];
        return $map[$n] ?? "رقم {$n}";
    }

    /**
     * Ordinal workshop number in English.
     */
    public static function englishOrdinal(int $n): string
    {
        $map = [
            1 => 'first',  2 => 'second',  3 => 'third',
            4 => 'fourth', 5 => 'fifth',   6 => 'sixth',
            7 => 'seventh',8 => 'eighth',  9 => 'ninth',
            10 => 'tenth', 11 => 'eleventh', 12 => 'twelfth',
        ];
        return $map[$n] ?? "{$n}th";
    }

    /**
     * Ordinal in Arabic (full phrase) e.g. "الورشة الأولى"
     */
    public static function arabicWorkshopLabel(int $n): string
    {
        return 'الورشة ' . self::arabicOrdinal($n);
    }

    /**
     * Format a date in Arabic.
     */
    public static function formatDateAr(\DateTimeInterface $date): string
    {
        $days = ['Sunday' => 'الأحد', 'Monday' => 'الاثنين', 'Tuesday' => 'الثلاثاء',
                 'Wednesday' => 'الأربعاء', 'Thursday' => 'الخميس', 'Friday' => 'الجمعة', 'Saturday' => 'السبت'];
        $months = [1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
                   5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
                   9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'];

        $dayName  = $days[$date->format('l')] ?? '';
        $monthName = $months[(int)$date->format('n')] ?? '';
        $day  = self::toArabicNumerals($date->format('j'));
        $year = self::toArabicNumerals($date->format('Y'));

        return "{$dayName} {$day} {$monthName} {$year}";
    }

    /**
     * Format a date in English.
     */
    public static function formatDateEn(\DateTimeInterface $date): string
    {
        return $date->format('l, j F Y');
    }

    /**
     * Convert ASCII digits to Arabic-Indic numerals (٠١٢٣٤٥٦٧٨٩).
     */
    public static function toArabicNumerals(string $number): string
    {
        $arabic = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        return preg_replace_callback('/\d/', fn($m) => $arabic[$m[0]], $number);
    }

    /**
     * Translate training hours to Arabic phrase.
     */
    public static function trainingHoursAr(int $hours): string
    {
        $map = [1 => 'ساعة تدريبية', 2 => 'ساعتان تدريبيتان'];
        if ($hours >= 3 && $hours <= 10) {
            return self::toArabicNumerals((string)$hours) . ' ساعات تدريبية';
        }
        return $map[$hours] ?? (self::toArabicNumerals((string)$hours) . ' ساعة تدريبية');
    }

    /**
     * Translate location type to display strings.
     */
    public static function locationLabel(string $type): array
    {
        return match ($type) {
            'online'  => ['en' => 'Online',   'ar' => 'عن بُعد'],
            'hybrid'  => ['en' => 'Hybrid',   'ar' => 'مختلط'],
            'offline' => ['en' => 'On Site',  'ar' => 'في الموقع'],
            default   => ['en' => 'Online',   'ar' => 'عن بُعد'],
        };
    }
}
