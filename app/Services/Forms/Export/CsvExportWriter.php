<?php

namespace App\Services\Forms\Export;

/**
 * CSV, streamed to the file a row at a time: any number of responses needs the same small amount of memory.
 * UTF-8 with a byte-order mark so Excel opens Arabic correctly; every cell is neutralised against formula injection.
 */
final class CsvExportWriter
{
    /** @param  array<string, mixed>  $meta  unused by CSV: a CSV carries the table and nothing else */
    public function write(string $path, FormResponseDataset $dataset, array $meta = []): int
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the export file for writing.');
        }

        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_map(fn ($c) => SpreadsheetSafe::cell($c['label']), $dataset->columns()), ',', '"', '\\');

            return $dataset->each(function (array $row) use ($handle) {
                fputcsv($handle, array_map(SpreadsheetSafe::cell(...), $row), ',', '"', '\\');
            });
        } finally {
            fclose($handle);
        }
    }
}
