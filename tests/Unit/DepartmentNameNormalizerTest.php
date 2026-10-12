<?php

namespace Tests\Unit;

use App\Support\DepartmentNameNormalizer as N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DepartmentNameNormalizerTest extends TestCase
{
    private const NAME = 'إدارة الذكاء الاصطناعي والتحول الرقمي';

    /** @return array<string, array{string}> */
    public static function harmlessVariants(): array
    {
        return [
            'trailing space' => [self::NAME.' '],
            'leading space' => ['  '.self::NAME],
            'doubled space' => ['إدارة  الذكاء الاصطناعي والتحول الرقمي'],
            'detached waw' => ['إدارة الذكاء الاصطناعي و التحول الرقمي'],
            'tab and newline' => ["إدارة\tالذكاء\nالاصطناعي والتحول الرقمي"],
            'non-breaking space' => ["إدارة\u{00A0}الذكاء الاصطناعي والتحول الرقمي"],
            'zero-width space' => ["إدارة\u{200B} الذكاء الاصطناعي والتحول الرقمي"],
            'zero-width joiner' => ["إدارة الذكاء\u{200D} الاصطناعي والتحول الرقمي"],
            'RLM mark' => [self::NAME."\u{200F}"],
            'BOM' => ["\u{FEFF}".self::NAME],
            'tatweel' => ['إدارة الذكـاء الاصطنـاعي والتحـول الرقمي'],
            'alef hamza variant' => ['ادارة الذكاء الاصطناعي والتحول الرقمي'],
            'alef madda variant' => ['آدارة الذكاء الاصطناعي والتحول الرقمي'],
            'arabic comma' => ['إدارة الذكاء الاصطناعي، والتحول الرقمي'],
            'trailing punctuation' => [self::NAME.'.'],
            'parentheses' => ['(إدارة الذكاء الاصطناعي والتحول الرقمي)'],
            'diacritics' => ['إِدَارَة الذكاء الاصطناعي والتحول الرقمي'],
        ];
    }

    #[DataProvider('harmlessVariants')]
    public function test_harmless_variants_normalize_to_the_same_key(string $variant): void
    {
        $this->assertSame(N::key(self::NAME), N::key($variant));
    }

    public function test_english_case_and_punctuation_are_ignored(): void
    {
        $this->assertSame(N::key('AI & Digital Transformation'), N::key('  ai  digital   transformation. '));
    }

    public function test_arabic_indic_digits_match_latin_digits(): void
    {
        $this->assertSame(N::key('فريق 2026'), N::key('فريق ٢٠٢٦'));
    }

    public function test_genuinely_different_departments_do_not_collide(): void
    {
        $this->assertNotSame(N::key('إدارة الشراكات والعلاقات'), N::key('إدارة الاستشارات والمستشارين'));
        $this->assertNotSame(N::key('الإدارة المالية'), N::key('الإدارة التقنية والفنية'));
    }

    public function test_a_waw_that_is_part_of_a_word_is_not_detached(): void
    {
        // "وحدة" (unit) starts with waw but must not be glued to the previous word.
        $this->assertNotSame(N::key('الإدارة وحدة'), N::key('الإدارةوحدة'));
        $this->assertSame('الاداره وحده', N::looseKey('الإدارة وحدة'));
    }

    public function test_loose_key_folds_letters_that_the_safe_key_keeps_apart(): void
    {
        // taa marbuta vs haa is a different spelling, possibly a different word:
        // safe key keeps them apart, loose key (review tier only) joins them.
        $this->assertNotSame(N::key('إدارة الصحة النفسية'), N::key('إدارة الصحة النفسيه'));
        $this->assertSame(N::looseKey('إدارة الصحة النفسية'), N::looseKey('إدارة الصحة النفسيه'));
    }

    public function test_null_and_empty_are_empty_keys(): void
    {
        $this->assertSame('', N::key(null));
        $this->assertSame('', N::key(''));
        $this->assertSame('', N::key(" \u{200B} "));
    }

    public function test_similarity_is_high_for_near_names_and_low_for_unrelated_ones(): void
    {
        $this->assertGreaterThanOrEqual(90.0, N::similarity(N::key('إدارة الذكاء الاصطناعي والتحول الرقمي'), N::key('إدارة الذكاء الاصطناعي والتحول الرقمى الحديث')));
        $this->assertLessThan(75.0, N::similarity(N::key('الإدارة المالية'), N::key('إدارة الإعلام والتسويق')));
        $this->assertSame(0.0, N::similarity('', 'x'));
    }
}
