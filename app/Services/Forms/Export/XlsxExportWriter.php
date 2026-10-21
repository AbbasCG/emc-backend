<?php

namespace App\Services\Forms\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * XLSX with an Arabic right-to-left sheet. Every text cell is written with an explicit STRING type (never parsed, so never a
 * formula) and also neutralised by SpreadsheetSafe; only genuine numbers in numeric columns are written as numbers.
 *
 * A workbook is built in memory, so the service refuses sizes beyond FormExportService::XLSX_MAX_CELLS and points to CSV.
 */
final class XlsxExportWriter
{
    private const MAX_NUMERIC_DIGITS = 15;

    /** @param  array{title?: string}  $meta */
    public function write(string $path, FormResponseDataset $dataset, array $meta = []): int
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setCreator('EMC')->setTitle(self::sheetTitle($meta['title'] ?? 'Responses'));
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::sheetTitle($meta['title'] ?? 'Responses'));
        $sheet->setRightToLeft(true);

        $columns = $dataset->columns();
        foreach ($columns as $i => $column) {
            $coordinate = Coordinate::stringFromColumnIndex($i + 1).'1';
            $sheet->setCellValueExplicit($coordinate, SpreadsheetSafe::cell($column['label']), DataType::TYPE_STRING);
            $sheet->getColumnDimensionByColumn($i + 1)->setWidth(min(48, max(14, mb_strlen($column['label']) + 6)));
        }
        $last = Coordinate::stringFromColumnIndex(max(1, count($columns)));
        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'font'      => ['bold' => true],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->freezePane('A2');

        $numeric = array_map(fn ($c) => $c['type'] !== null && in_array($c['type'], FormExportColumns::NUMERIC_TYPES, true), $columns);

        $rowNumber = 1;
        $count = $dataset->each(function (array $row) use ($sheet, $numeric, &$rowNumber) {
            $rowNumber++;
            foreach ($row as $i => $value) {
                $coordinate = Coordinate::stringFromColumnIndex($i + 1).$rowNumber;
                if ($numeric[$i] && self::isStorableNumber($value)) {
                    $sheet->setCellValueExplicit($coordinate, (float) str_replace(',', '.', $value), DataType::TYPE_NUMERIC);

                    continue;
                }
                $sheet->setCellValueExplicit($coordinate, SpreadsheetSafe::cell($value), DataType::TYPE_STRING);
            }
        });

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $count;
    }

    /** A spreadsheet keeps 15 significant digits: longer numbers stay text so nothing is silently rounded. */
    private static function isStorableNumber(string $value): bool
    {
        return preg_match('/^-?\d{1,'.self::MAX_NUMERIC_DIGITS.'}([.,]\d{1,6})?$/', $value) === 1;
    }

    /** Excel sheet names: at most 31 characters, none of \ / ? * [ ] : */
    private static function sheetTitle(string $title): string
    {
        $clean = trim((string) preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $title));

        return mb_substr($clean === '' ? 'Responses' : $clean, 0, 31);
    }
}
