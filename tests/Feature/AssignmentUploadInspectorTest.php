<?php

namespace Tests\Feature;

use App\Services\AssignmentUploads\AssignmentUploadPolicy;
use App\Services\AssignmentUploads\UploadedFileInspector;
use App\Services\AssignmentUploads\UploadRejectedException;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsUploadFixtures;
use Tests\TestCase;

/**
 * The content sniffer: extension only picks the signature, bytes decide.
 */
class AssignmentUploadInspectorTest extends TestCase
{
    use BuildsUploadFixtures;

    private function inspect(UploadedFile $file)
    {
        return app(UploadedFileInspector::class)->inspect($file);
    }

    private function assertRejected(UploadedFile $file, string $reason): void
    {
        try {
            $this->inspect($file);
        } catch (UploadRejectedException $e) {
            $this->assertSame($reason, $e->reason, "Expected [{$reason}] but got [{$e->reason}]: {$e->getMessage()}");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $e->getMessage(), 'Message must be Arabic.');

            return;
        }
        $this->fail("Expected the upload [{$file->getClientOriginalName()}] to be rejected with [{$reason}].");
    }

    public static function validFiles(): array
    {
        return [
            'jpg'  => ['a.jpg', 'jpgBytes', 'image/jpeg'],
            'jpeg' => ['a.JPEG', 'jpgBytes', 'image/jpeg'],
            'png'  => ['a.png', 'pngBytes', 'image/png'],
            'webp' => ['a.webp', 'webpBytes', 'image/webp'],
            'pdf'  => ['a.pdf', 'pdfBytes', 'application/pdf'],
            'doc'  => ['a.doc', fn ($t) => $t->oleBytes('WordDocument'), 'application/msword'],
            'docx' => ['a.docx', fn ($t) => $t->ooxmlBytes('word'), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls'  => ['a.xls', fn ($t) => $t->oleBytes('Workbook'), 'application/vnd.ms-excel'],
            'xlsx' => ['a.xlsx', fn ($t) => $t->ooxmlBytes('xl'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'ppt'  => ['a.ppt', fn ($t) => $t->oleBytes('PowerPoint Document'), 'application/vnd.ms-powerpoint'],
            'pptx' => ['a.pptx', fn ($t) => $t->ooxmlBytes('ppt'), 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'txt'  => ['a.txt', fn () => "hello\nworld\n", 'text/plain'],
            'txt utf8 arabic' => ['a.txt', fn () => "\xEF\xBB\xBF".'مرحبا بالعالم', 'text/plain'],
            'txt utf16' => ['a.txt', fn () => "\xFF\xFE".mb_convert_encoding('hello', 'UTF-16LE', 'UTF-8'), 'text/plain'],
            'csv'  => ['a.csv', fn () => "a,b\n1,2\n", 'text/csv'],
            'csv cp1256' => ['a.csv', fn () => iconv('UTF-8', 'Windows-1256', "الاسم,العمر\nعلي,20\n"), 'text/csv'],
            'zip'  => ['a.zip', fn ($t) => $t->zipBytes(), 'application/zip'],
            'rar'  => ['a.rar', fn ($t) => $t->rarBytes(), 'application/vnd.rar'],
            '7z'   => ['a.7z', fn ($t) => $t->sevenZipBytes(), 'application/x-7z-compressed'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validFiles')]
    public function test_genuine_files_are_accepted_with_server_decided_mime(string $name, string|\Closure $bytes, string $mime): void
    {
        $content = $bytes instanceof \Closure ? $bytes($this) : $this->{$bytes}();
        // The browser's Content-Type is a lie on purpose: it must be ignored.
        $file = new UploadedFile($this->tmp($content), $name, 'application/x-evil', null, true);

        $result = $this->inspect($file);

        $this->assertSame($mime, $result->mime);
        $this->assertSame(hash('sha256', $content), $result->sha256);
        $this->assertSame(strlen($content), $result->size);
    }

    private function tmp(string $content): string
    {
        $p = tempnam(sys_get_temp_dir(), 'ins');
        file_put_contents($p, $content);

        return $p;
    }

    public static function disguisedFiles(): array
    {
        return [
            'php source named jpg'     => ['evil.jpg', '<?php echo 1; ?>', 'content_mismatch'],
            'exe named pdf'            => ['invoice.pdf', 'exe', 'executable'],
            'bare MZ header named pdf' => ['x.pdf', "MZ\x90\x00".str_repeat("\0", 200), 'executable'],
            'elf named zip'            => ['x.zip', "\x7FELF".str_repeat("\0", 60), 'executable'],
            'shebang named txt'        => ['run.txt', "#!/bin/sh\nrm -rf /\n", 'executable'],
            'plain text named zip'     => ['x.zip', 'just some text', 'content_mismatch'],
            'plain text named rar'     => ['x.rar', 'just some text', 'content_mismatch'],
            'plain text named 7z'      => ['x.7z', 'just some text', 'content_mismatch'],
            'text named docx'          => ['x.docx', 'not a zip at all', 'content_mismatch'],
            'text named pdf'           => ['x.pdf', 'not a pdf', 'content_mismatch'],
            'png named jpg'            => ['x.jpg', 'png', 'content_mismatch'],
            'jpg named webp'           => ['x.webp', 'jpg', 'content_mismatch'],
            'truncated jpeg header'    => ['x.jpg', "\xFF\xD8\xFF\xE0garbage", 'content_mismatch'],
            'zip without ooxml parts named docx' => ['x.docx', 'plainzip', 'content_mismatch'],
            'xlsx package named docx'  => ['x.docx', 'xlsx', 'content_mismatch'],
            'docx without content types' => ['x.docx', 'noct', 'content_mismatch'],
            'macro docx'               => ['x.docx', 'macro', 'macros'],
            'binary named txt'         => ['x.txt', "ab\0cd\0ef\x01\x02", 'content_mismatch'],
            'zip named txt'            => ['x.txt', 'plainzip', 'content_mismatch'],
            'xls stream named doc'     => ['x.doc', 'olexls', 'content_mismatch'],
            'msi-like ole named doc'   => ['x.doc', 'olemsi', 'content_mismatch'],
            'empty file'               => ['x.pdf', '', 'empty'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('disguisedFiles')]
    public function test_disguised_or_broken_files_are_rejected(string $name, string $kind, string $reason): void
    {
        $bytes = match ($kind) {
            'exe'      => $this->exeBytes(),
            'png'      => $this->pngBytes(),
            'jpg'      => $this->jpgBytes(),
            'plainzip' => $this->zipBytes(),
            'xlsx'     => $this->ooxmlBytes('xl'),
            'noct'     => $this->ooxmlBytes('word', false),
            'macro'    => $this->ooxmlBytes('word', true, ['word/vbaProject.bin' => 'x']),
            'olexls'   => $this->oleBytes('Workbook'),
            'olemsi'   => $this->oleBytes('SummaryInformation'),
            default    => $kind,
        };

        $this->assertRejected($this->upload($name, $bytes), $reason);
    }

    public static function unsupportedExtensions(): array
    {
        return array_map(fn ($e) => [$e], [
            'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'sh', 'php', 'php5', 'phtml', 'phar', 'js', 'mjs', 'vbs', 'ps1',
            'jar', 'html', 'htm', 'svg', 'hta', 'scr', 'docm', 'gif', 'mp4', 'exe ',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedExtensions')]
    public function test_unsupported_extensions_are_rejected_even_with_harmless_content(string $ext): void
    {
        $this->assertRejected($this->upload('file.'.$ext, 'hello'), 'unsupported_type');
    }

    public function test_file_without_extension_is_unsupported(): void
    {
        $this->assertRejected($this->upload('README', 'hello'), 'unsupported_type');
    }

    public function test_double_extension_is_judged_by_the_last_one_and_content(): void
    {
        $this->assertRejected($this->upload('shell.php.jpg', "<?php echo 1;"), 'content_mismatch');
        // A genuine image keeps working; it is stored under an internal uuid name, never under this one.
        $this->assertSame('jpg', $this->inspect($this->upload('weird.php.jpg', $this->jpgBytes()))->extension);
    }

    public function test_size_limits_follow_the_group_and_name_the_limit_in_arabic(): void
    {
        config(['assignment_uploads.groups.images.max_mb' => 0.0001]); // ~104 bytes
        try {
            $this->inspect($this->upload('big.png', $this->pngBytes().str_repeat('0', 400)));
            $this->fail('expected too_large');
        } catch (UploadRejectedException $e) {
            $this->assertSame('too_large', $e->reason);
            $this->assertStringContainsString('الحد الأقصى', $e->getMessage());
            $this->assertStringContainsString('كيلوبايت', $e->getMessage());
        }

        config(['assignment_uploads.groups.text.max_mb' => 2]);
        $this->assertRejected($this->upload('big.txt', str_repeat('a', 2 * 1048576 + 1)), 'too_large');
    }

    public function test_per_group_limits_are_the_specified_defaults(): void
    {
        $policy = app(AssignmentUploadPolicy::class);
        $mb = 1048576;
        $this->assertSame(5 * $mb, $policy->groupForExtension('png')['max_bytes']);
        $this->assertSame(5 * $mb, $policy->groupForExtension('webp')['max_bytes']);
        $this->assertSame(10 * $mb, $policy->groupForExtension('pdf')['max_bytes']);
        $this->assertSame(10 * $mb, $policy->groupForExtension('docx')['max_bytes']);
        $this->assertSame(10 * $mb, $policy->groupForExtension('xlsx')['max_bytes']);
        $this->assertSame(10 * $mb, $policy->groupForExtension('csv')['max_bytes']);
        $this->assertSame(10 * $mb, $policy->groupForExtension('pptx')['max_bytes']);
        $this->assertSame(10 * $mb, $policy->groupForExtension('zip')['max_bytes']);
        $this->assertSame(10 * $mb, $policy->groupForExtension('7z')['max_bytes']);
        $this->assertSame(2 * $mb, $policy->groupForExtension('txt')['max_bytes']);
        $this->assertSame(5, $policy->maxFiles());
        $this->assertSame(25 * $mb, $policy->maxTotalBytes());
        $this->assertNull($policy->groupForExtension('exe'));
    }

    public function test_zip_with_path_traversal_entry_is_rejected(): void
    {
        foreach (['../evil.php', 'a/../../evil.php', '/etc/passwd', 'C:\\Windows\\x.dll', '..\\evil.exe'] as $entry) {
            $this->assertRejected($this->upload('x.zip', $this->zipBytes([$entry => 'x'])), 'zip_unsafe_path');
        }
        $this->assertTrue(UploadedFileInspector::isUnsafeZipEntryName("a\0b"));
        $this->assertFalse(UploadedFileInspector::isUnsafeZipEntryName('dir/sub/file.txt'));
        $this->assertFalse(UploadedFileInspector::isUnsafeZipEntryName('..hidden/file'));
    }

    public function test_zip_entry_count_and_bomb_guards(): void
    {
        config(['assignment_uploads.zip.max_entries' => 3]);
        $many = array_combine(array_map(fn ($i) => "f{$i}.txt", range(1, 4)), array_fill(0, 4, 'x'));
        $this->assertRejected($this->upload('x.zip', $this->zipBytes($many)), 'zip_too_many_entries');

        config(['assignment_uploads.zip.max_entries' => 2000, 'assignment_uploads.zip.max_uncompressed_mb' => 0.0001]);
        $this->assertRejected($this->upload('x.zip', $this->zipBytes(['a.txt' => str_repeat('A', 5000)])), 'zip_bomb');

        // High ratio once above the floor: zeros compress ~1000:1.
        config(['assignment_uploads.zip.max_uncompressed_mb' => 500, 'assignment_uploads.zip.ratio_floor_mb' => 1, 'assignment_uploads.zip.max_ratio' => 50]);
        $this->assertRejected($this->upload('x.zip', $this->zipBytes(['zeros.bin' => str_repeat("\0", 3 * 1048576)])), 'zip_bomb');
    }

    public function test_corrupt_zip_is_rejected(): void
    {
        $this->assertRejected($this->upload('x.zip', "PK\x03\x04".str_repeat('x', 200)), 'zip_corrupt');
    }

    public function test_display_name_is_sanitised_but_keeps_arabic(): void
    {
        $this->assertSame('تقرير الواجب.pdf', UploadedFileInspector::sanitizeDisplayName('C:\\fakepath\\تقرير  الواجب.pdf'));
        $this->assertSame('passwd.png', UploadedFileInspector::sanitizeDisplayName('../../etc/passwd.png'));
        // Bidi override (RLO) and zero-width characters are removed so "gpj.exe"-style spoofing cannot be displayed.
        $this->assertSame('photoexe.jpg', UploadedFileInspector::sanitizeDisplayName("photo\u{202E}exe\u{200B}.jpg"));
        $this->assertSame('ab.txt', UploadedFileInspector::sanitizeDisplayName("a\x00b\x07.txt"));
        $this->assertSame('x.png', UploadedFileInspector::sanitizeDisplayName('<x>.png'));

        $long = UploadedFileInspector::sanitizeDisplayName(str_repeat('ا', 400).'.pdf');
        $this->assertLessThanOrEqual(180, mb_strlen($long));
        $this->assertStringEndsWith('.pdf', $long);

        $this->assertSame('ملف', UploadedFileInspector::sanitizeDisplayName(''));
    }

    public function test_ini_shorthand_parser(): void
    {
        $this->assertSame(8 * 1048576, AssignmentUploadPolicy::iniToBytes('8M'));
        $this->assertSame(2 * 1073741824, AssignmentUploadPolicy::iniToBytes('2G'));
        $this->assertSame(512 * 1024, AssignmentUploadPolicy::iniToBytes('512K'));
        $this->assertSame(0, AssignmentUploadPolicy::iniToBytes('0'));
        $this->assertSame('10 ميجابايت', AssignmentUploadPolicy::formatBytesAr(10 * 1048576));
        $this->assertSame('2.5 ميجابايت', AssignmentUploadPolicy::formatBytesAr((int) (2.5 * 1048576)));
    }

    // ── Round 2: legacy OLE macros / directory parsing ─────────────────────────────────────────────────────

    public static function macroStreams(): array
    {
        return [
            'Macros storage'       => [['Macros']],
            'VBA storage'          => [['VBA']],
            '_VBA_PROJECT'         => [['_VBA_PROJECT']],
            '_VBA_PROJECT_CUR'     => [['_VBA_PROJECT_CUR', 'VBA']],
            'case-insensitive'     => [['macros']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('macroStreams')]
    public function test_legacy_office_files_with_macros_are_rejected_for_every_legacy_format(array $extra): void
    {
        foreach (['doc' => 'WordDocument', 'xls' => 'Workbook', 'ppt' => 'PowerPoint Document'] as $ext => $stream) {
            $this->assertRejected($this->upload("m.{$ext}", $this->oleBytes($stream, $extra)), 'macros');
        }
    }

    public function test_clean_legacy_office_files_are_accepted_including_multi_sector_directories(): void
    {
        $this->assertSame('doc', $this->inspect($this->upload('a.doc', $this->oleBytes('WordDocument', ['1Table', 'SummaryInformation', 'DocumentSummaryInformation'])))->extension);
        $this->assertSame('xls', $this->inspect($this->upload('a.xls', $this->oleBytes('Book')))->extension);

        // 3+ directory sectors (4 entries each): the chain is followed, the stream is still found.
        $many = array_map(fn ($i) => "Stream{$i}", range(1, 14));
        $this->assertSame('doc', $this->inspect($this->upload('big.doc', $this->oleBytes('WordDocument', $many)))->extension);
        $this->assertRejected($this->upload('big-macro.doc', $this->oleBytes('WordDocument', [...$many, 'Macros'])), 'macros');
    }

    public function test_the_characteristic_stream_must_be_a_directory_entry_not_a_substring(): void
    {
        // A compound file whose directory has NO WordDocument entry but whose BYTES contain the UTF-16 name (an .msi-like trick).
        $bytes = $this->oleBytes('SummaryInformation').mb_convert_encoding('WordDocument', 'UTF-16LE', 'UTF-8');
        $this->assertRejected($this->upload('trick.doc', $bytes), 'content_mismatch');

        // Cross-format: a workbook named .doc / a document named .xls.
        $this->assertRejected($this->upload('x.doc', $this->oleBytes('Workbook')), 'content_mismatch');
        $this->assertRejected($this->upload('x.xls', $this->oleBytes('WordDocument')), 'content_mismatch');
    }

    public function test_rtf_html_and_truncated_files_named_doc_are_rejected(): void
    {
        $this->assertRejected($this->upload('a.doc', '{\rtf1\ansi Hello}'), 'content_mismatch');
        $this->assertRejected($this->upload('a.doc', '<html><body>hi</body></html>'), 'content_mismatch');
        $this->assertRejected($this->upload('a.doc', substr($this->oleBytes('WordDocument'), 0, 700)), 'content_mismatch');
        // Header claims directory at a sector far beyond the end of the file.
        $broken = substr_replace($this->oleBytes('WordDocument'), pack('V', 5000), 0x30, 4);
        $this->assertRejected($this->upload('b.doc', $broken), 'content_mismatch');
    }

    // ── Round 2: OOXML hardening ───────────────────────────────────────────────────────────────────────────

    private function packageWithRels(string $relsXml, string $folder = 'word'): string
    {
        return $this->ooxmlBytes($folder, true, [$folder.'/_rels/document.xml.rels' => $relsXml]);
    }

    private function rels(string $type, string $target, bool $external): string
    {
        $mode = $external ? ' TargetMode="External"' : '';

        return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'.$type.'" Target="'.$target.'"'.$mode.'/></Relationships>';
    }

    public function test_ooxml_with_dangerous_external_relationships_is_rejected_but_plain_hyperlinks_are_allowed(): void
    {
        foreach (['attachedTemplate', 'oleObject', 'frame', 'subDocument', 'externalLink'] as $type) {
            $this->assertRejected(
                $this->upload('t.docx', $this->packageWithRels($this->rels($type, 'http://evil.example/x', true))),
                'external_relationship',
            );
        }

        // An ordinary external hyperlink, and an INTERNAL relationship of a dangerous-sounding type, are fine.
        $this->assertSame('docx', $this->inspect($this->upload('h.docx', $this->packageWithRels($this->rels('hyperlink', 'https://example.com/', true))))->extension);
        $this->assertSame('docx', $this->inspect($this->upload('i.docx', $this->packageWithRels($this->rels('oleObject', 'embeddings/o.bin', false))))->extension);
    }

    public function test_ooxml_with_activex_embedded_programs_or_vba_is_rejected(): void
    {
        $this->assertRejected($this->upload('a.docx', $this->ooxmlBytes('word', true, ['word/activeX/activeX1.xml' => '<x/>'])), 'active_content');
        $this->assertRejected($this->upload('b.docx', $this->ooxmlBytes('word', true, ['word/embeddings/payload.exe' => 'MZ'])), 'embedded_executable');
        $this->assertRejected($this->upload('c.xlsx', $this->ooxmlBytes('xl', true, ['xl/embeddings/run.ps1' => 'x'])), 'embedded_executable');
        $this->assertRejected($this->upload('d.docx', $this->ooxmlBytes('word', true, ['word/vbaProject.bin' => 'x'])), 'macros');
        // Embedded non-program parts (images, .bin printer settings, nested office files) remain legitimate.
        $ok = $this->ooxmlBytes('word', true, ['word/media/image1.png' => 'x', 'word/printerSettings/p.bin' => 'x', 'word/embeddings/sheet.xlsx' => 'x']);
        $this->assertSame('docx', $this->inspect($this->upload('ok.docx', $ok))->extension);
    }

    public function test_real_world_package_with_backslash_and_leading_slash_part_names_is_accepted(): void
    {
        // Some Windows / .NET writers produce `word\document.xml` or `/word/document.xml`.
        foreach (['word\\document.xml', '/word/document.xml'] as $part) {
            $path = tempnam(sys_get_temp_dir(), 'pkg');
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);
            $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
            $zip->addFromString($part, '<doc/>');
            $zip->close();
            $bytes = (string) file_get_contents($path);
            @unlink($path);

            $this->assertSame('docx', $this->inspect($this->upload('win.docx', $bytes))->extension, $part);
        }
    }

    public function test_zip_symlink_entries_are_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lnk');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);
        $zip->addFromString('link', '/etc/passwd');
        $zip->setExternalAttributesName('link', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        $this->assertRejected($this->upload('s.zip', $bytes), 'zip_unsafe_path');
    }

    public function test_default_zip_entry_cap_is_ten_thousand(): void
    {
        $this->assertSame(10000, config('assignment_uploads.zip.max_entries'));
    }

    // ── Round 2: UTF-16 text is decoded and judged per code unit ───────────────────────────────────────────

    public function test_utf16_text_is_checked_per_code_unit(): void
    {
        $le = fn (string $s) => "\xFF\xFE".mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
        $be = fn (string $s) => "\xFE\xFF".mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');

        $this->assertSame('txt', $this->inspect($this->upload('a.txt', $le("مرحبا\r\nhello")))->extension);
        $this->assertSame('csv', $this->inspect($this->upload('a.csv', $be("a,b\n1,2\n")))->extension);

        // A BOM in front of binary data (NUL code unit, control characters, odd length, BOM only) is not text.
        $this->assertRejected($this->upload('b.txt', "\xFF\xFE".'a'.chr(0).chr(0).chr(0).'b'.chr(0)), 'content_mismatch');
        $this->assertRejected($this->upload('c.txt', "\xFF\xFE".implode(chr(0), array_map('chr', range(1, 8))).chr(0)), 'content_mismatch');
        $this->assertRejected($this->upload('d.txt', $le('hello').'x'), 'content_mismatch');
        $this->assertRejected($this->upload('e.txt', "\xFF\xFE"), 'content_mismatch');
        $this->assertRejected($this->upload('f.csv', $be('ok').chr(0).chr(0)), 'content_mismatch');
    }

    // ── Round 2: I/O failures are rejections, never 500s ───────────────────────────────────────────────────

    public function test_an_unreadable_file_is_an_arabic_rejection(): void
    {
        // The temp file vanishes between the upload and the check (cleanup, antivirus, a full disk).
        $tmp = tempnam(sys_get_temp_dir(), 'gone');
        file_put_contents($tmp, 'x');
        $file = new UploadedFile($tmp, 'a.pdf', 'application/pdf', UPLOAD_ERR_OK, true);
        unlink($tmp);

        $this->assertRejected($file, 'unreadable');
    }

    public function test_the_compound_file_reader_rejects_garbage_without_throwing_anything_else(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cfb');
        file_put_contents($path, random_bytes(2048));
        $this->expectException(\RuntimeException::class);

        try {
            \App\Services\AssignmentUploads\CompoundFileReader::entryNames($path);
        } finally {
            @unlink($path);
        }
    }
}
