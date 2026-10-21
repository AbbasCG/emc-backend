<?php

namespace Tests\Unit;

use App\Services\Forms\Export\PdfExportWriter;
use PHPUnit\Framework\TestCase;

/**
 * Which cells of a PDF export are isolated as left-to-right. Rendered with mPDF, a Latin value beginning with a symbol (`=1+1`,
 * `-2+3`, `@SUM(A1)`, `+966...`) comes out as "1+1=" inside the right-to-left row unless it is isolated; an Arabic cell, with or without a
 * Latin value inside it, is ordered correctly by the default algorithm and must be left alone (isolating inside it made it worse).
 * The visual result was checked on rendered pages; this pins the rule that produces it.
 */
class FormPdfCellDirectionTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function latinCells(): array
    {
        return [
            'formula' => ['=1+1'], 'negative expression' => ['-2+3'], 'at sign' => ['@SUM(A1:A9)'], 'international phone' => ['+966501234567'],
            'hyperlink formula' => ['=HYPERLINK("http://evil.test","click")'], 'date and time' => ['2026-10-18 22:37'], 'e-mail' => ['user@example.com'],
            'hash tag' => ['#tag'], 'percentage' => ['50%'], 'phrase' => ['Excellent course 100%'], 'plain number' => ['42'],
        ];
    }

    /** @dataProvider latinCells */
    #[\PHPUnit\Framework\Attributes\DataProvider('latinCells')]
    public function test_a_cell_with_no_arabic_is_isolated_as_left_to_right_whole(string $text): void
    {
        $html = PdfExportWriter::cell($text);

        $this->assertStringStartsWith('<bdo dir="ltr">', $html);
        $this->assertStringEndsWith('</bdo>', $html);
        $this->assertSame($text, htmlspecialchars_decode(substr($html, strlen('<bdo dir="ltr">'), -strlen('</bdo>')), ENT_QUOTES), 'the text itself is unchanged');
    }

    /** @return array<string, array{0: string}> */
    public static function arabicCells(): array
    {
        return [
            'arabic' => ['نص عربي'], 'arabic with a formula inside' => ['اسم =1+1 عربي'], 'arabic with a phone' => ['الرقم: +966501234567 للتواصل'],
            'arabic with an expression' => ['الإجابة -2+3 صحيحة'], 'mixed phrase' => ['ممتاز Excellent course 100% جداً'],
        ];
    }

    /** @dataProvider arabicCells */
    #[\PHPUnit\Framework\Attributes\DataProvider('arabicCells')]
    public function test_a_cell_with_arabic_in_it_is_left_to_the_default_algorithm(string $text): void
    {
        $this->assertSame(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), PdfExportWriter::cell($text));
        $this->assertStringNotContainsString('<bdo', PdfExportWriter::cell($text));
    }

    public function test_empty_and_symbol_only_cells_are_left_alone_and_markup_is_always_escaped(): void
    {
        $this->assertSame('', PdfExportWriter::cell(''));
        $this->assertSame('—', PdfExportWriter::cell('—'));
        $this->assertSame('-', PdfExportWriter::cell('-'));

        $html = PdfExportWriter::cell('<b>&amp;</b> x');
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertSame('<bdo dir="ltr">&lt;b&gt;&amp;amp;&lt;/b&gt; x</bdo>', $html);
        $this->assertSame('نص &lt;script&gt;', PdfExportWriter::cell('نص <script>'));
    }
}
