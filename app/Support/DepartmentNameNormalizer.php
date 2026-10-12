<?php

namespace App\Support;

/**
 * Canonical comparison keys for department names.
 *
 * These keys are for MATCHING only — they are never stored or displayed.
 *
 * key()      safe, high-confidence: removes only differences that are
 *            invisible or purely typographic (case, spacing, punctuation,
 *            tatweel, diacritics, bidi/zero-width marks, alef-hamza variants,
 *            alef maqsura, Arabic-Indic digits, a detached conjunction waw).
 * looseKey() additionally folds letters that CAN be genuinely different words
 *            (taa marbuta/haa, hamza on waw/ya). Equality at this level is
 *            only ever reported for human review, never auto-merged.
 */
final class DepartmentNameNormalizer
{
    private const ALEF_YA = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ى' => 'ي',
    ];

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    private const LOOSE = [
        'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي', 'ء' => '',
    ];

    public static function key(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $s = mb_strtolower($value, 'UTF-8');

        // Zero-width, bidi, soft-hyphen and BOM characters.
        $s = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}\x{00AD}\x{061C}]/u', '', $s);

        // Tatweel, harakat and superscript alef.
        $s = (string) preg_replace('/[\x{0640}\x{064B}-\x{065F}\x{0670}]/u', '', $s);

        $s = strtr($s, self::ALEF_YA);
        $s = strtr($s, self::DIGITS);

        // Punctuation and symbols (Arabic and Latin alike) become separators.
        $s = (string) preg_replace('/[\p{P}\p{S}]+/u', ' ', $s);

        // Every kind of whitespace collapses to one space.
        $s = (string) preg_replace('/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]+/u', ' ', $s);

        // "الذكاء و التحول" and "الذكاء والتحول" are the same phrase.
        $s = (string) preg_replace('/(?<![^\s])و\s+(?=\S)/u', 'و', $s);

        return trim($s);
    }

    public static function looseKey(?string $value): string
    {
        return strtr(self::key($value), self::LOOSE);
    }

    /** Similarity 0–100 between two keys, for the review tier only. */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        $tokensA = array_values(array_unique(explode(' ', $a)));
        $tokensB = array_values(array_unique(explode(' ', $b)));
        $union = count(array_unique(array_merge($tokensA, $tokensB)));
        $jaccard = $union > 0 ? count(array_intersect($tokensA, $tokensB)) / $union * 100 : 0.0;

        return max($jaccard, self::lcsRatio($a, $b));
    }

    /**
     * Longest-common-subsequence ratio over characters (not bytes — similar_text
     * on UTF-8 inflates scores because Arabic letters share lead bytes).
     */
    private static function lcsRatio(string $a, string $b): float
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $n = count($x);
        $m = count($y);
        if ($n === 0 || $m === 0) {
            return 0.0;
        }

        $prev = array_fill(0, $m + 1, 0);
        for ($i = 1; $i <= $n; $i++) {
            $row = [0];
            for ($j = 1; $j <= $m; $j++) {
                $row[$j] = $x[$i - 1] === $y[$j - 1] ? $prev[$j - 1] + 1 : max($prev[$j], $row[$j - 1]);
            }
            $prev = $row;
        }

        return 2 * $prev[$m] / ($n + $m) * 100;
    }
}
