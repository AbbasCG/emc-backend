<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * A file uploaded through a public form question. The file's own bytes decide, never its name or the content
 * type the browser sent.
 *
 *  - the upload itself must be valid;
 *  - the extension must be on the allow list (documents, spreadsheets, plain text and common images — no
 *    scripts, no HTML, no SVG, no archives);
 *  - the content type detected from the bytes must be one of the types that extension legitimately has;
 *  - PDFs must start with `%PDF-`, Office Open XML files with the ZIP signature.
 *
 * The size ceiling stays a separate `max:` rule on the field.
 */
class FormAttachmentFile implements ValidationRule
{
    public const MAX_KB = 5120;

    /** The extensions an image question accepts (a subset of ALLOWED). */
    public const IMAGES = ['jpg', 'jpeg', 'png', 'webp'];

    public const MESSAGE_IMAGE = 'المسموح صور بصيغة JPG أو PNG أو WebP فقط.';

    /** @param  list<string>  $only  restrict to these extensions (empty = everything in ALLOWED) */
    public function __construct(private readonly array $only = []) {}

    public const MESSAGE = 'نوع الملف غير مسموح. المسموح: PDF وصور (JPG وPNG وWebP) ومستندات Word وExcel وملفات نصية وCSV.';

    /** extension => content types finfo may report for a genuine file of that kind */
    public const ALLOWED = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/csv', 'text/plain', 'application/csv'],
        'doc'  => ['application/msword', 'application/CDFV2', 'application/x-ole-storage'],
        'xls'  => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    /** The lower-cased, allow-listed extension of an upload, or null. */
    public static function extensionOf(UploadedFile $file): ?string
    {
        $ext = strtolower($file->getClientOriginalExtension());

        return isset(self::ALLOWED[$ext]) ? $ext : null;
    }

    /**
     * The content type finfo reads from the bytes, or null when the upload cannot be read at all. A file that cannot be opened
     * (another process holds it, a scanner removed or locked it) is not something to answer with a server error: it is simply
     * not a file that can be accepted.
     */
    public static function detectedMime(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        if ($path === false) {
            return null;
        }

        try {
            return (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail(self::MESSAGE);

            return;
        }

        $ext = self::extensionOf($value);
        $mime = self::detectedMime($value);
        if ($ext !== null && $this->only !== [] && ! in_array($ext, $this->only, true)) {
            $fail($this->only === self::IMAGES ? self::MESSAGE_IMAGE : self::MESSAGE);

            return;
        }
        if ($ext === null || $mime === null || ! in_array($mime, self::ALLOWED[$ext], true)) {
            $fail($this->only === self::IMAGES ? self::MESSAGE_IMAGE : self::MESSAGE);

            return;
        }

        try {
            $head = (string) file_get_contents($value->getRealPath(), false, null, 0, 5);
        } catch (\Throwable) {
            $fail($this->only === self::IMAGES ? self::MESSAGE_IMAGE : self::MESSAGE);

            return;
        }
        $signatureOk = match (true) {
            $ext === 'pdf' => str_starts_with($head, '%PDF-'),
            in_array($ext, ['docx', 'xlsx'], true) => str_starts_with($head, "PK"),
            default => true,
        };

        if (! $signatureOk) {
            $fail(self::MESSAGE);
        }
    }
}
