<?php

namespace App\Services\AssignmentUploads;

use DOMDocument;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

/**
 * Decides whether ONE uploaded file may be accepted, from its CONTENT.
 *
 * Nothing here trusts the file extension on its own, or the browser's Content-Type: the extension only selects
 * which signature the bytes must match, and the stored MIME type is the one the server decides for that verified
 * extension. A mismatch (php source named a.jpg, an .exe named .pdf, plain text named .zip, a zip without OOXML
 * parts named .docx, an .msi named .doc, ...) is refused with an Arabic reason.
 *
 * Archives are NEVER extracted. For zip/OOXML the central directory is listed (names + declared sizes) to reject
 * path traversal, symlinks, huge entry counts and zip bombs; rar / 7z are verified by magic bytes only (their contents
 * cannot be listed without an extraction library, which is a documented limit).
 *
 * This class does not log: it throws UploadRejectedException with a stable `reason` and the caller (the request
 * object) writes ONE aggregated log line per request, so a flood of bad files cannot flood the log.
 */
class UploadedFileInspector
{
    /** Streams/storages whose presence proves a legacy Office file carries VBA macros. */
    private const OLE_MACRO_NAMES = ['_vba_project', '_vba_project_cur', 'vba', 'macros'];

    /** Relationship types that, when EXTERNAL, pull in a remote template / object / frame / sub-document / workbook. */
    private const DANGEROUS_EXTERNAL_RELATIONSHIPS = ['attachedtemplate', 'oleobject', 'frame', 'subdocument', 'externallink', 'externallinkpath'];

    public function __construct(private readonly AssignmentUploadPolicy $policy)
    {
    }

    /**
     * @throws UploadRejectedException
     */
    public function inspect(UploadedFile $file): InspectedUpload
    {
        $display = self::sanitizeDisplayName($file->getClientOriginalName());

        $this->assertTransferredIntact($file, $display);

        $path = $file->getRealPath() ?: $file->getPathname();
        if (! $path || ! is_file($path) || ! is_readable($path)) {
            throw $this->unreadable($display);
        }

        $extension = self::extensionOf($display);
        $group = $extension !== '' ? $this->policy->groupForExtension($extension) : null;

        if ($group === null || $this->policy->isBlockedExtension($extension)) {
            throw new UploadRejectedException('unsupported_type', $this->unsupportedTypeMessage());
        }

        $size = @filesize($path);
        if ($size === false) {
            throw $this->unreadable($display);
        }
        if ($size <= 0) {
            throw new UploadRejectedException('empty', 'الملف فارغ. اختر ملفاً يحتوي على محتوى.');
        }

        if ($size > $group['max_bytes']) {
            throw new UploadRejectedException(
                'too_large',
                'حجم الملف أكبر من المسموح. الحد الأقصى '.AssignmentUploadPolicy::formatBytesAr($group['max_bytes']).'.',
            );
        }

        try {
            $this->verifyContent($path, $extension, $size);
            $sha = @hash_file('sha256', $path);
        } catch (UploadRejectedException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw $this->unreadable($display);
        }
        if ($sha === false) {
            throw $this->unreadable($display);
        }

        return new InspectedUpload(
            file: $file,
            displayName: $display,
            extension: $extension,
            mime: $this->policy->canonicalMime($extension),
            size: (int) $size,
            sha256: $sha,
            group: $group['key'],
        );
    }

    public function unsupportedTypeMessage(): string
    {
        return 'نوع الملف غير مدعوم. يمكنك رفع الصور وPDF وWord وExcel والملفات المضغوطة.';
    }

    private function unreadable(string $display): UploadRejectedException
    {
        return new UploadRejectedException('unreadable', "تعذّر قراءة الملف «{$display}». أعد رفعه من جديد.");
    }

    // ── PHP-level transfer failures (UPLOAD_ERR_*) ────────────────────────────────────────────────────────────

    private function assertTransferredIntact(UploadedFile $file, string $display): void
    {
        $error = $file->getError();
        if ($error === UPLOAD_ERR_OK) {
            return;
        }

        // The REAL limit, in the same units the user reads everywhere else (never the raw ini string such as "2M").
        $serverLimit = AssignmentUploadPolicy::formatBytesAr(AssignmentUploadPolicy::iniToBytes(ini_get('upload_max_filesize')));

        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "تعذّر رفع الملف «{$display}»: حجمه أكبر مما يقبله الخادم حالياً (الحد {$serverLimit}). قلّل حجم الملف أو تواصل مع الدعم الفني لرفع الحد.",
            UPLOAD_ERR_PARTIAL => "انقطع رفع الملف «{$display}» قبل أن يكتمل. تحقق من اتصالك بالإنترنت وأعد المحاولة.",
            default => "تعذّر على الخادم استلام الملف «{$display}» بسبب مشكلة مؤقتة في التخزين. أعد المحاولة لاحقاً، وإذا استمرت المشكلة فتواصل مع الدعم الفني.",
        };

        throw new UploadRejectedException('upload_error_'.$error, $message);
    }

    // ── Content verification ───────────────────────────────────────────────────────────────────────────────────

    /**
     * @throws UploadRejectedException|RuntimeException (RuntimeException = an I/O failure, mapped to "unreadable")
     */
    private function verifyContent(string $path, string $ext, int $size): void
    {
        $head = $this->readBytes($path, 0, 65536);

        if ($this->looksExecutable($head)) {
            throw new UploadRejectedException('executable', 'الملفات التنفيذية والسكربتات غير مسموح بها.');
        }

        $ok = match ($ext) {
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF") && $this->imageType($path) === IMAGETYPE_JPEG,
            'png'         => str_starts_with($head, "\x89PNG\r\n\x1A\n") && $this->imageType($path) === IMAGETYPE_PNG,
            'webp'        => strlen($head) >= 12 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' && $this->imageType($path) === IMAGETYPE_WEBP,
            'pdf'         => ($pos = strpos($head, '%PDF-')) !== false && $pos < 1024,
            'rar'         => str_starts_with($head, "Rar!\x1A\x07"),
            '7z'          => str_starts_with($head, "7z\xBC\xAF\x27\x1C"),
            'zip'         => $this->isZipMagic($head),
            'doc'         => $this->isOle2($head) && $this->verifyOle($path, ['worddocument']),
            'xls'         => $this->isOle2($head) && $this->verifyOle($path, ['workbook', 'book']),
            'ppt'         => $this->isOle2($head) && $this->verifyOle($path, ['powerpoint document'], true),
            'docx'        => $this->isZipMagic($head) && $this->isOoxml($path, 'word/'),
            'xlsx'        => $this->isZipMagic($head) && $this->isOoxml($path, 'xl/'),
            'pptx'        => $this->isZipMagic($head) && $this->isOoxml($path, 'ppt/'),
            'txt', 'csv'  => $this->isPlainText($path, $head, $size),
            default       => false,
        };

        if (! $ok) {
            throw new UploadRejectedException(
                'content_mismatch',
                'محتوى الملف لا يطابق امتداده (.'.$ext.'). تأكد أن الملف سليم وأنه لم تُغيَّر صيغته بإعادة التسمية.',
            );
        }

        // Plain zip: list entries (never extract) and refuse unsafe / bomb-like archives.
        if ($ext === 'zip') {
            $this->assertZipIsSafe($path, $size);
        }
    }

    private function readBytes(string $path, int $offset, int $length): string
    {
        $data = @file_get_contents($path, false, null, $offset, $length);
        if ($data === false) {
            throw new RuntimeException('unreadable');
        }

        return $data;
    }

    private function looksExecutable(string $head): bool
    {
        if (str_starts_with($head, "\x7FELF") || str_starts_with($head, '#!')) {
            return true;
        }
        // Mach-O thin / fat binaries and Java class files share these magics.
        foreach (["\xCF\xFA\xED\xFE", "\xCE\xFA\xED\xFE", "\xFE\xED\xFA\xCE", "\xFE\xED\xFA\xCF", "\xCA\xFE\xBA\xBE"] as $magic) {
            if (str_starts_with($head, $magic)) {
                return true;
            }
        }
        if (str_starts_with($head, 'MZ')) {
            // A bare "MZ" prefix is legal text ("MZ report"); require a real PE/DOS-stub signature.
            if (str_contains(substr($head, 0, 1024), 'This program cannot be run in DOS mode') || str_contains(substr($head, 0, 1024), 'This program must be run under Win')) {
                return true;
            }
            if (strlen($head) >= 64) {
                $peOffset = unpack('V', substr($head, 0x3C, 4))[1];
                if ($peOffset > 0 && $peOffset + 4 <= strlen($head) && substr($head, $peOffset, 4) === "PE\0\0") {
                    return true;
                }
            }
            // MZ followed by binary bytes in the first 64 bytes is not text either.
            if (strlen($head) >= 64 && str_contains(substr($head, 0, 64), "\0")) {
                return true;
            }
        }

        return false;
    }

    private function imageType(string $path): int|false
    {
        $info = @getimagesize($path);

        return $info === false ? false : $info[2];
    }

    private function isZipMagic(string $head): bool
    {
        return str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06");
    }

    private function isOle2(string $head): bool
    {
        return str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
    }

    /**
     * Legacy Office (doc/xls/ppt): parse the compound-file DIRECTORY (not a substring search over the file). The
     * characteristic stream of the claimed format must be a real directory entry, which rejects .msi and other OLE2
     * containers renamed to .doc; and a VBA project / macros storage is refused outright. For .ppt, whose VBA lives
     * INSIDE the `PowerPoint Document` stream rather than in a storage, that stream is read (bounded) and its record
     * tree is walked, see {@see assertPptHasNoVba()}.
     *
     * @param list<string> $requiredAnyOf lower-case names, one of which must exist
     *
     * @throws UploadRejectedException when macros are present
     */
    private function verifyOle(string $path, array $requiredAnyOf, bool $scanPptRecords = false): bool
    {
        try {
            $names = CompoundFileReader::entryNames($path); // every spelling, lower-case, NULs removed
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'unreadable') {
                throw $e;
            }

            return false; // malformed container
        }

        if (array_intersect(self::OLE_MACRO_NAMES, $names) !== []) {
            throw new UploadRejectedException('macros', 'الملفات التي تحتوي على وحدات ماكرو غير مسموح بها.');
        }

        if (array_intersect($requiredAnyOf, $names) === []) {
            return false;
        }

        if ($scanPptRecords) {
            $this->assertPptHasNoVba($path);
        }

        return true;
    }

    /** Largest `PowerPoint Document` stream that is scanned (the whole file is capped lower than this by the per-file limit). */
    private const PPT_STREAM_CAP = 10 * 1048576;

    private const PPT_MAX_DEPTH = 32;

    private const PPT_MAX_RECORDS = 1000000;

    /**
     * PowerPoint 97-2003 keeps its VBA project as records of the `PowerPoint Document` stream (a VbaInfo container
     * 0x03FF / VbaInfoAtom 0x0400 pointing at an ExOleObjStg 0x1011 record that holds the compressed project), NOT as a
     * compound-file storage, so the directory check alone would pass a macro-enabled .ppt. The record tree (8-byte
     * headers: recVer/recInstance, recType, recLen; containers have recVer 0xF) is walked with every length checked
     * against its parent. FAIL CLOSED: a stream that is too large, or whose records do not parse, is refused.
     *
     * @throws UploadRejectedException
     */
    private function assertPptHasNoVba(string $path): void
    {
        try {
            $data = CompoundFileReader::readStream($path, 'powerpoint document', self::PPT_STREAM_CAP);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'unreadable') {
                throw $e;
            }

            throw $this->unscannable();
        }

        if ($data === null) {
            throw $this->unscannable();
        }

        $count = 0;
        $this->walkPptRecords($data, 0, strlen($data), 0, $count);
    }

    private function unscannable(): UploadRejectedException
    {
        return new UploadRejectedException('unscannable', 'تعذّر فحص محتوى ملف PowerPoint بأمان (بنية غير سليمة أو كبيرة جداً). احفظه بصيغة pptx وأعد رفعه.');
    }

    private function walkPptRecords(string $data, int $start, int $end, int $depth, int &$count): void
    {
        $pos = $start;
        while ($end - $pos >= 8) {
            if (++$count > self::PPT_MAX_RECORDS) {
                throw $this->unscannable();
            }

            $h = unpack('vverInst/vtype/Vlen', substr($data, $pos, 8));

            // Zero padding at the end of the stream (or of a container) is not a record.
            if ($h['verInst'] === 0 && $h['type'] === 0 && $h['len'] === 0) {
                if (trim(substr($data, $pos, $end - $pos), "\0") === '') {
                    return;
                }
            }

            $body = $pos + 8;
            if ($h['len'] > $end - $body) {
                throw $this->unscannable(); // a record that does not fit its parent: fail closed
            }

            $type = $h['type'];
            if ($type === 0x03FF || $type === 0x0400) {
                throw new UploadRejectedException('macros', 'الملفات التي تحتوي على وحدات ماكرو غير مسموح بها.');
            }
            if ($type === 0x1011) {
                $this->assertOleObjectStorageHasNoVba(substr($data, $body, $h['len']), $h['verInst'] >> 4);
            }

            if (($h['verInst'] & 0xF) === 0xF) {
                if ($depth >= self::PPT_MAX_DEPTH) {
                    throw $this->unscannable();
                }
                $this->walkPptRecords($data, $body, $body + $h['len'], $depth + 1, $count);
            }

            $pos = $body + $h['len'];
        }

        if ($pos < $end && trim(substr($data, $pos, $end - $pos), "\0") !== '') {
            throw $this->unscannable(); // trailing bytes that are not a record header
        }
    }

    /** ExOleObjStg: recInstance 1 = zlib-compressed OLE storage (4-byte uncompressed size first), 0 = stored as is. */
    private function assertOleObjectStorageHasNoVba(string $payload, int $instance): void
    {
        if ($instance === 1) {
            $bytes = strlen($payload) > 4 ? @gzuncompress(substr($payload, 4), 4 * 1048576) : false;
            if ($bytes === false) {
                throw $this->unscannable();
            }
        } else {
            $bytes = $payload;
        }

        foreach (['_VBA_PROJECT', 'PROJECTwm'] as $marker) {
            if (str_contains($bytes, mb_convert_encoding($marker, 'UTF-16LE', 'UTF-8'))) {
                throw new UploadRejectedException('macros', 'الملفات التي تحتوي على وحدات ماكرو غير مسموح بها.');
            }
        }
    }

    /** One zip entry name as the OOXML consumer would see it: percent-decoded (repeatedly), `\` to `/`, leading `/` stripped. */
    private static function normalisePartName(string $name, bool $keepLeadingSlash = false): string
    {
        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($name);
            if ($decoded === $name) {
                break;
            }
            $name = $decoded;
        }

        $name = str_replace('\\', '/', $name);

        return $keepLeadingSlash ? $name : ltrim($name, '/');
    }

    private const RELS_MAX_FILES = 200;

    private const RELS_MAX_FILE_BYTES = 262144;

    private const RELS_TOTAL_BUDGET = 4194304;

    /**
     * A real OOXML package: a zip with [Content_Types].xml and the format's top-level folder (names are normalised:
     * percent-decoded, `\` -> `/`, leading `/` removed, because genuine Windows/.NET writers produce those), with no VBA
     * project, no ActiveX, no embedded program, no DOCTYPE, and no EXTERNAL relationship except an ordinary hyperlink.
     * The relationship parts are scanned FAIL CLOSED: more of them, or bigger ones, than a real document has is a
     * rejection (never "scan the first N and ignore the rest").
     *
     * @throws UploadRejectedException
     */
    private function isOoxml(string $path, string $folder): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            $this->assertZipIsSafe($path, (int) filesize($path), $zip, true);

            $embeddedBlocked = array_map('strtolower', (array) config('assignment_uploads.embedded_blocked_extensions', []));
            $contentTypesIndex = null;
            $hasFolder = false;
            $rels = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = self::normalisePartName((string) $zip->getNameIndex($i));
                $lower = strtolower($name);

                if ($name === '[Content_Types].xml') {
                    $contentTypesIndex = $i;
                }
                if (str_starts_with($name, $folder)) {
                    $hasFolder = true;
                }
                if (str_ends_with($lower, 'vbaproject.bin')) {
                    throw new UploadRejectedException('macros', 'الملفات التي تحتوي على وحدات ماكرو غير مسموح بها.');
                }
                if (in_array('activex', explode('/', $lower), true)) {
                    throw new UploadRejectedException('active_content', 'الملفات التي تحتوي على عناصر ActiveX غير مسموح بها.');
                }
                if (! str_ends_with($name, '/') && in_array(self::extensionOf($name), $embeddedBlocked, true)) {
                    throw new UploadRejectedException('embedded_executable', 'يحتوي الملف على ملف تنفيذي أو سكربت مضمّن ولا يمكن قبوله.');
                }
                // Relationship parts live in a `_rels` folder (OPC); only those are consulted by Office.
                if (preg_match('#(^|/)_rels/[^/]*\.rels$#', $lower) === 1) {
                    $rels[] = $i;
                }
            }

            if ($contentTypesIndex === null || ! $hasFolder) {
                return false;
            }

            if (count($rels) > self::RELS_MAX_FILES) {
                throw new UploadRejectedException('rels_limit', 'يحتوي الملف على عدد غير طبيعي من أجزاء الارتباطات ولا يمكن فحصه بأمان.');
            }

            $budget = 0;
            foreach ([$contentTypesIndex, ...$rels] as $index) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new UploadRejectedException('content_mismatch', 'محتوى الملف لا يطابق امتداده. تأكد أن الملف سليم.');
                }
                if ($stat['size'] > self::RELS_MAX_FILE_BYTES) {
                    throw new UploadRejectedException('rels_too_large', 'يحتوي الملف على جزء ارتباطات أكبر من الحد المسموح ولا يمكن فحصه بأمان.');
                }
                $budget += $stat['size'];
                if ($budget > self::RELS_TOTAL_BUDGET) {
                    throw new UploadRejectedException('rels_budget', 'مجموع أجزاء الارتباطات في الملف أكبر من الحد المسموح ولا يمكن فحصه بأمان.');
                }
            }

            foreach ([$contentTypesIndex, ...$rels] as $index) {
                $this->assertXmlPartIsSafe($zip, $index, $index !== $contentTypesIndex);
            }

            return true;
        } finally {
            $zip->close();
        }
    }

    /** External relationship targets are only ever allowed for ordinary hyperlinks, and only to these schemes. */
    private const HYPERLINK_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * Parses one small XML part (size already bounded by the caller). A DOCTYPE is refused outright (defence in depth
     * against entity tricks), and in a relationship part the ONLY External relationship allowed is a hyperlink to
     * http / https / mailto: every other External type (image, package, video, audio, oleObject, attachedTemplate,
     * frame, subDocument, externalLink, ...) pulls remote content in and is refused.
     *
     * @throws UploadRejectedException
     */
    private function assertXmlPartIsSafe(ZipArchive $zip, int $index, bool $isRelationships): void
    {
        $xml = $zip->getFromIndex($index, self::RELS_MAX_FILE_BYTES);
        $dom = new DOMDocument();
        if ($xml === false || ! @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new UploadRejectedException('content_mismatch', 'محتوى الملف لا يطابق امتداده. تأكد أن الملف سليم.');
        }

        if ($dom->doctype !== null) {
            throw new UploadRejectedException('doctype_forbidden', 'يحتوي الملف على تعريف DOCTYPE غير مسموح به.');
        }

        if (! $isRelationships) {
            return;
        }

        foreach ($dom->getElementsByTagNameNS('*', 'Relationship') as $rel) {
            if (strcasecmp($rel->getAttribute('TargetMode'), 'External') !== 0) {
                continue;
            }

            $type = strtolower(basename(str_replace('\\', '/', rtrim($rel->getAttribute('Type'), '/'))));
            $scheme = strtolower((string) parse_url(trim($rel->getAttribute('Target')), PHP_URL_SCHEME));

            if ($type !== 'hyperlink' || ! in_array($scheme, self::HYPERLINK_SCHEMES, true)) {
                throw new UploadRejectedException('external_relationship', 'يحتوي الملف على ارتباط خارجي بقالب أو كائن أو صورة أو مصدر بيانات ولا يمكن قبوله.');
            }
        }
    }

    /**
     * Lists the zip central directory (no extraction) and rejects traversal / symlinks / bombs.
     *
     * @throws UploadRejectedException
     */
    private function assertZipIsSafe(string $path, int $compressedSize, ?ZipArchive $open = null, bool $ooxml = false): void
    {
        $zip = $open;
        if ($zip === null) {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw new UploadRejectedException('zip_corrupt', 'الملف المضغوط تالف أو غير مقروء.');
            }
        }

        try {
            $cfg = (array) config('assignment_uploads.zip', []);
            $maxEntries = (int) ($cfg['max_entries'] ?? 10000);
            $maxUncompressed = (int) round((float) ($cfg['max_uncompressed_mb'] ?? 500) * 1048576);
            $maxRatio = (int) ($cfg['max_ratio'] ?? 200);
            $ratioFloor = (int) round((float) ($cfg['ratio_floor_mb'] ?? 20) * 1048576);

            if ($zip->numFiles > $maxEntries) {
                throw new UploadRejectedException('zip_too_many_entries', "الملف المضغوط يحتوي على عدد كبير جداً من الملفات (الحد الأقصى {$maxEntries}).");
            }

            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i, ZipArchive::FL_UNCHANGED);
                if ($stat === false) {
                    throw new UploadRejectedException('zip_corrupt', 'الملف المضغوط تالف أو غير مقروء.');
                }

                if (self::isUnsafeZipEntryName((string) $stat['name'], $ooxml) || $this->isSymlinkEntry($zip, $i)) {
                    throw new UploadRejectedException('zip_unsafe_path', 'يحتوي الملف المضغوط على مسارات غير آمنة ولا يمكن قبوله.');
                }

                $total += (int) $stat['size'];
                if ($total > $maxUncompressed) {
                    throw new UploadRejectedException('zip_bomb', 'حجم محتوى الملف المضغوط بعد الفك كبير جداً ولا يمكن قبوله.');
                }
            }

            if ($total > $ratioFloor && $compressedSize > 0 && ($total / $compressedSize) > $maxRatio) {
                throw new UploadRejectedException('zip_bomb', 'نسبة ضغط الملف غير طبيعية ولا يمكن قبوله.');
            }
        } finally {
            if ($open === null) {
                $zip->close();
            }
        }
    }

    /** A unix symlink entry (mode S_IFLNK in the upper 16 bits of the external attributes). */
    private function isSymlinkEntry(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        return $opsys === ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000);
    }

    /** `$allowLeadingSlash`: OOXML packages from some Windows/.NET writers name parts "/word/document.xml"; they are normalised, not rejected. */
    public static function isUnsafeZipEntryName(string $name, bool $allowLeadingSlash = false): bool
    {
        if ($name === '' || str_contains($name, "\0")) {
            return true;
        }
        // Judge the name the way a consumer would read it: percent-decoded, so `%2e%2e/` cannot smuggle a traversal.
        $normalised = self::normalisePartName($name, true);
        if (str_contains($normalised, "\0") || str_contains(rawurldecode($name), "\0")) {
            return true;
        }

        if ((! $allowLeadingSlash && str_starts_with($normalised, '/')) || preg_match('/^[A-Za-z]:/', ltrim($normalised, '/')) === 1) {
            return true;
        }

        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * txt / csv: "valid text". UTF-8 (with or without BOM) and legacy 8-bit encodings (for example Windows-1256 Arabic
     * exported by Excel) pass as long as the bytes look like text: no NUL, a low share of control characters.
     * UTF-16 (BOM) is DECODED and judged per code unit by the same rules, so a binary blob that merely starts with a
     * UTF-16 BOM is refused.
     */
    private function isPlainText(string $path, string $head, int $size): bool
    {
        // Known binary formats are never "text", even if every byte happened to be printable.
        foreach (["PK\x03\x04", '%PDF-', "\x89PNG", "\xFF\xD8\xFF", "\xD0\xCF\x11\xE0", 'Rar!', "7z\xBC\xAF", 'GIF8', 'RIFF'] as $magic) {
            if (str_starts_with($head, $magic)) {
                return false;
            }
        }

        if (str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF")) {
            return $this->isUtf16Text($path, str_starts_with($head, "\xFE\xFF"), $size);
        }

        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw new RuntimeException('unreadable');
        }

        $control = 0;
        $seen = 0;
        try {
            while (! feof($h)) {
                $chunk = fread($h, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException('unreadable');
                }
                if ($chunk === '') {
                    break;
                }
                if (str_contains($chunk, "\0")) {
                    return false;
                }
                $seen += strlen($chunk);
                // Control characters other than TAB LF FF CR (and ESC, which old terminal dumps contain).
                $control += strlen($chunk) - strlen(strtr($chunk, array_fill_keys(array_map('chr', array_merge(range(0, 8), [11], range(14, 26), range(28, 31))), '')));
            }
        } finally {
            fclose($h);
        }

        return $seen > 0 && ($control / $seen) <= 0.02;
    }

    private function isUtf16Text(string $path, bool $bigEndian, int $size): bool
    {
        if ($size < 4 || $size % 2 !== 0) {
            return false; // a BOM alone, or a truncated code unit
        }

        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw new RuntimeException('unreadable');
        }

        $format = $bigEndian ? 'n*' : 'v*';
        $units = 0;
        $control = 0;
        try {
            fseek($h, 2); // skip the BOM
            while (! feof($h)) {
                $chunk = fread($h, 262144);
                if ($chunk === false) {
                    throw new RuntimeException('unreadable');
                }
                if ($chunk === '') {
                    break;
                }
                if (strlen($chunk) % 2 === 1) {
                    $extra = fread($h, 1); // keep code units whole
                    $chunk .= $extra === false ? '' : $extra;
                    if (strlen($chunk) % 2 === 1) {
                        return false;
                    }
                }

                foreach (unpack($format, $chunk) as $unit) {
                    if ($unit === 0) {
                        return false; // NUL code unit: binary, not text
                    }
                    if ($unit < 32 && $unit !== 9 && $unit !== 10 && $unit !== 12 && $unit !== 13) {
                        $control++;
                    }
                    $units++;
                }
            }
        } finally {
            fclose($h);
        }

        return $units > 0 && ($control / $units) <= 0.02;
    }

    // ── Names ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** Lower-case extension after the last dot of an already-sanitised name ('' when none). */
    public static function extensionOf(string $name): string
    {
        $base = ($slash = strrpos($name, '/')) === false ? $name : substr($name, $slash + 1);
        $dot = strrpos($base, '.');
        if ($dot === false || $dot === strlen($base) - 1) {
            return '';
        }

        return strtolower(substr($base, $dot + 1));
    }

    /**
     * Name kept for DISPLAY only: path parts removed, control / bidi-override / zero-width characters removed,
     * characters that are hostile in headers or markup removed, length limited. Arabic letters and spaces are kept.
     */
    public static function sanitizeDisplayName(?string $name): string
    {
        $name = (string) $name;
        // Strip any path (both separators, since the client may be on Windows).
        $name = (string) preg_replace('#^.*[\\\\/]#us', '', $name);
        // Invisible/control/bidi-override characters (U+202A-202E, U+2066-2069, U+200B-200F, U+061C, U+FEFF) and C0/C1.
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', '', $name);
        // Characters that are unsafe in markup / headers / Windows file names.
        $name = (string) preg_replace('/[<>:"|?*\x{FF1C}\x{FF1E}]/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .\t");

        $max = max(20, (int) config('assignment_uploads.display_name_max', 180));
        if (mb_strlen($name) > $max) {
            $ext = self::extensionOf($name);
            $suffix = ($ext !== '' && mb_strlen($ext) <= 10) ? '.'.$ext : '';
            $name = rtrim(mb_substr($name, 0, $max - mb_strlen($suffix)), ' .').$suffix;
        }

        return $name === '' ? 'ملف' : $name;
    }
}
