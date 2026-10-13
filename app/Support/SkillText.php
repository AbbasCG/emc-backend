<?php

namespace App\Support;

/**
 * Small, deterministic Arabic/English text helpers for skill matching.
 *
 * Skills arrive as free text on both sides (a staffing request's "skills and specs" and a
 * volunteer's "skills"), so matching needs a stable way to split them into skill phrases and
 * to compare words despite Arabic spelling variants. Nothing here calls an external service.
 */
final class SkillText
{
    /** Words that carry no skill information. */
    private const STOPWORDS = [
        'من', 'في', 'على', 'الى', 'عن', 'مع', 'او', 'ان', 'ذلك', 'هذا', 'هذه', 'التي', 'الذي', 'لدي', 'لديه', 'لديها',
        'خبره', 'خبرات', 'مهارات', 'مهاره', 'اتقان', 'معرفه', 'قدره', 'يفضل', 'مطلوب', 'ممتاز', 'جيد', 'عالي', 'كافي',
        'سنوات', 'سنه', 'متطوع', 'متطوعه', 'فريق', 'قسم', 'اداره', 'عمل', 'اعمال', 'مجال', 'كل', 'بعض', 'ضمن', 'حول', 'for', 'the', 'and', 'with', 'of', 'in', 'to', 'a',
    ];

    /** Lower-case, strip diacritics/tatweel and unify Arabic letter variants and punctuation. */
    public static function normalize(?string $text): string
    {
        $t = mb_strtolower((string) $text);
        $t = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $t);
        $t = strtr($t, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $t = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $t);

        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    /**
     * Split free text into skill phrases, keeping the original wording for display.
     *
     * @return array<int, string>
     */
    public static function phrases(?string $text, int $max = 20): array
    {
        $parts = preg_split('/[\r\n,،;؛\/|•·\-–—\.]+|\s+و\s+|\s+and\s+/u', (string) $text) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $display = trim((string) preg_replace('/\s+/u', ' ', $part));
            $display = trim($display, " \t:–-•*");
            if (mb_strlen($display) < 2 || mb_strlen($display) > 80) {
                continue;
            }
            $key = self::normalize($display);
            if ($key === '' || isset($out[$key])) {
                continue;
            }
            $out[$key] = $display;
            if (count($out) >= $max) {
                break;
            }
        }

        return array_values($out);
    }

    /**
     * Meaningful word stems of a text: no stopwords, no "ال"/"و" prefixes, at least 3 letters.
     *
     * @return array<int, string>
     */
    public static function keywords(?string $text): array
    {
        $out = [];
        foreach (explode(' ', self::normalize($text)) as $word) {
            $w = self::stem($word);
            if (mb_strlen($w) < 3 || in_array($w, self::STOPWORDS, true) || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $out[$w] = true;
        }

        return array_keys($out);
    }

    /** Strip a leading conjunction و and the definite article ال from a normalized word. */
    public static function stem(string $word): string
    {
        $w = $word;
        if (mb_strlen($w) > 4 && str_starts_with($w, 'و')) {
            $w = mb_substr($w, 1);
        }
        if (mb_strlen($w) > 4 && str_starts_with($w, 'ال')) {
            $w = mb_substr($w, 2);
        }

        return $w;
    }

    /** Two stems are the same word if equal, or if one extends the other by a short suffix/prefix (>=4 letters). */
    public static function sameWord(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $min = min(mb_strlen($a), mb_strlen($b));

        return $min >= 4 && (str_starts_with($a, $b) || str_starts_with($b, $a));
    }
}
