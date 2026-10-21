<?php

namespace App\Services\Forms\Export;

/**
 * Text that came from a respondent must never become a formula when a spreadsheet opens the file (CSV and Excel injection).
 *
 * A cell whose first visible character is `=`, `+`, `-` or `@`, or that starts with a tab, carriage return or line feed, would be
 * read by Excel, LibreOffice and Google Sheets as a formula or a command. Such a cell is written with a leading apostrophe, which
 * every one of them shows as plain text. A plain number (a rating, an amount, a phone number that is only digits) is not touched:
 * it cannot be a formula, and changing it would damage real data. Spreadsheet cells are also written with an explicit string type,
 * so this is a second layer, not the only one.
 */
final class SpreadsheetSafe
{
    public static function cell(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $first = $value[0];
        if ($first === "\t" || $first === "\r" || $first === "\n") {
            return "'".$value;
        }

        $visible = ltrim($value, " \u{00A0}");
        if ($visible === '') {
            return $value;
        }

        if (in_array($visible[0], ['=', '+', '-', '@'], true)) {
            return self::isPlainNumber($visible) ? $value : "'".$value;
        }

        return $value;
    }

    /** Digits with an optional sign and decimal part: nothing a spreadsheet could evaluate. */
    public static function isPlainNumber(string $value): bool
    {
        return preg_match('/^[+-]?\d{1,15}([.,]\d{1,15})?$/', $value) === 1;
    }
}
