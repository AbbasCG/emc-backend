<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;

/** Upload fixtures for CV tests: files whose BYTES are (or are not) what their name claims. */
trait MakesCvFiles
{
    /** A minimal but genuine PDF (signature, one object, trailer), padded with a comment up to roughly $kb kilobytes. */
    protected function pdfCv(string $name = 'cv.pdf', int $kb = 4): UploadedFile
    {
        $body = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n";
        $body .= '%'.str_repeat('x', max(0, $kb * 1024 - strlen($body) - 8))."\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($name, $body);
    }

    /** Word-processing bytes (a ZIP container, as .docx really is), under a name of your choice. */
    protected function docxCv(string $name = 'cv.docx'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "PK\x03\x04".str_repeat("\0", 2048));
    }

    /** An image that really is a PNG. */
    protected function pngCv(string $name = 'cv.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    /** A program (not a PDF) that simply carries a .pdf name. */
    protected function renamedExecutable(string $name = 'cv.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "MZ\x90\x00".str_repeat("\0", 2048));
    }
}
