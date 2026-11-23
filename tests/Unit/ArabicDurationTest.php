<?php

namespace Tests\Unit;

use App\Support\ArabicDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ArabicDurationTest extends TestCase
{
    /** @return array<string, array{0: int, 1: string}> */
    public static function cases(): array
    {
        return [
            'one minute' => [1, 'دقيقة'],
            'two minutes' => [2, 'دقيقتان'],
            'few minutes' => [5, '5 دقائق'],
            'ten minutes' => [10, '10 دقائق'],
            'eleven minutes' => [11, '11 دقيقة'],
            'forty five' => [45, '45 دقيقة'],
            'one hour' => [60, 'ساعة'],
            'two hours' => [120, 'ساعتان'],
            'few hours' => [180, '3 ساعات'],
            'ten hours' => [600, '10 ساعات'],
            'eleven hours' => [660, '11 ساعة'],
            'hour and minutes' => [75, 'ساعة و15 دقيقة'],
            'hour and one minute' => [61, 'ساعة ودقيقة'],
            'two hours and two minutes' => [122, 'ساعتان ودقيقتان'],
            'three hours five minutes' => [185, '3 ساعات و5 دقائق'],
            'twelve hours eleven minutes' => [731, '12 ساعة و11 دقيقة'],
            'maximum' => [60000, '1000 ساعة'],
        ];
    }

    #[DataProvider('cases')]
    public function test_only_non_zero_parts_with_arabic_plural_forms(int $minutes, string $expected): void
    {
        $this->assertSame($expected, ArabicDuration::describe($minutes));
    }

    public function test_zero_and_negative_never_produce_an_empty_string(): void
    {
        $this->assertSame('0 دقيقة', ArabicDuration::describe(0));
        $this->assertSame('0 دقيقة', ArabicDuration::describe(-5));
    }
}
