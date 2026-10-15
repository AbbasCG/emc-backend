<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * A CV / resume must be a real PDF. Used on every new CV upload and replacement.
 *
 * It does not trust the browser: the file's own bytes decide.
 *  - the upload itself must be valid;
 *  - the file name must end in .pdf (a Word or image file renamed to .pdf is caught by the next two checks);
 *  - the content type detected from the bytes (not the one the browser sent) must be application/pdf;
 *  - the file must start with the PDF signature `%PDF-`.
 *
 * The size limit stays where it was (`max:5120` on each field). Old CVs in other formats are never touched: this
 * only judges files being uploaded now.
 */
class PdfCvFile implements ValidationRule
{
    public const MESSAGE = 'يُسمح برفع السيرة الذاتية بصيغة PDF فقط.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail(self::MESSAGE);

            return;
        }

        if (strtolower($value->getClientOriginalExtension()) !== 'pdf') {
            $fail(self::MESSAGE);

            return;
        }

        $path = $value->getRealPath();
        if ($path === false || (new \finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf') {
            $fail(self::MESSAGE);

            return;
        }

        $handle = fopen($path, 'rb');
        $head = $handle ? fread($handle, 5) : '';
        if ($handle) {
            fclose($handle);
        }
        if ($head !== '%PDF-') {
            $fail(self::MESSAGE);
        }
    }
}
