<?php

namespace App\Services\Forms\Export;

use Mpdf\Mpdf;

/**
 * A printable PDF of the responses: Arabic text shaped and laid out right to left, a repeated table header, page numbers.
 * Built with mPDF, which does Arabic shaping and bidirectional text itself (the PDF library the platform used before leaves
 * Arabic disconnected and left-aligned). Landscape A4, so several columns fit; the service limits rows and columns to keep a
 * printable page.
 *
 * Only what is in the dataset is printed. Attachments appear as their file names: never an address or a path.
 */
final class PdfExportWriter
{
    /** @param  array{title?: string, subtitle?: string, generated_by?: string, filters?: list<string>}  $meta */
    public function write(string $path, FormResponseDataset $dataset, array $meta = []): int
    {
        $tmp = storage_path('framework/cache/mpdf');
        if (! is_dir($tmp)) {
            @mkdir($tmp, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode'              => 'utf-8',
            'format'            => 'A4-L',
            'tempDir'           => $tmp,
            'default_font'      => 'xbriyaz',
            'default_font_size' => 9,
            'autoArabic'        => true,
            'autoScriptToLang'  => true,
            'autoLangToFont'    => true,
            'margin_left'       => 10,
            'margin_right'      => 10,
            'margin_top'        => 12,
            'margin_bottom'     => 14,
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->SetTitle((string) ($meta['title'] ?? 'Responses'));
        $mpdf->SetCreator('EMC');
        $mpdf->SetHTMLFooter('<div style="text-align:center;font-size:8pt;color:rgb(100,116,139)">صفحة {PAGENO} من {nbpg}</div>');

        $head = '';
        foreach ($dataset->columns() as $column) {
            $head .= '<th>'.self::e($column['label']).'</th>';
        }

        $rows = '';
        $count = $dataset->each(function (array $row) use (&$rows) {
            $rows .= '<tr>';
            foreach ($row as $cell) {
                $rows .= '<td>'.self::cell($cell).'</td>';
            }
            $rows .= '</tr>';
        });

        $filters = '';
        foreach ($meta['filters'] ?? [] as $line) {
            $filters .= '<div>'.self::e($line).'</div>';
        }

        $mpdf->WriteHTML(self::styles(), \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML(
            '<h1>'.self::e((string) ($meta['title'] ?? '')).'</h1>'
            .'<div class="meta">'.self::e((string) ($meta['subtitle'] ?? '')).'</div>'
            .($filters !== '' ? '<div class="meta">'.$filters.'</div>' : '')
            .'<table><thead><tr>'.$head.'</tr></thead><tbody>'.$rows.'</tbody></table>',
            \Mpdf\HTMLParserMode::HTML_BODY,
        );
        $mpdf->Output($path, \Mpdf\Output\Destination::FILE);

        return $count;
    }

    /**
     * One cell as HTML. A cell with no Arabic in it (a number, an e-mail address, a date, a formula-looking text such as `=1+1` or
     * `-2+3`) is isolated as left-to-right with `<bdo dir="ltr">`: left alone, the bidirectional algorithm of a right-to-left row drags a
     * leading `=`, `-`, `+` or `@` to the wrong end ("1+1="). A cell with Arabic in it is left to the default algorithm, which orders
     * Arabic text with a Latin value inside it correctly (isolating the Latin run there made it worse: measured on rendered pages).
     */
    public static function cell(string $text): string
    {
        $safe = self::e($text);
        if (preg_match('/\p{Arabic}/u', $text) === 1 || preg_match('/[A-Za-z0-9]/', $text) !== 1) {
            return $safe;
        }

        return '<bdo dir="ltr">'.$safe.'</bdo>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function styles(): string
    {
        return 'body { direction: rtl; font-family: xbriyaz; }
            h1 { font-size: 15pt; margin: 0 0 4pt 0; }
            .meta { font-size: 8.5pt; color: rgb(71,85,105); margin-bottom: 3pt; }
            table { width: 100%; border-collapse: collapse; margin-top: 6pt; }
            th { background-color: rgb(226,232,240); font-weight: bold; text-align: right; border: 0.4pt solid rgb(148,163,184); padding: 3pt 4pt; }
            td { text-align: right; border: 0.4pt solid rgb(203,213,225); padding: 3pt 4pt; vertical-align: top; }';
    }
}
