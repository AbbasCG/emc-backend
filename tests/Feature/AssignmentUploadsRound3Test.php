<?php

namespace Tests\Feature;

use App\Services\AssignmentUploads\CompoundFileReader;
use App\Services\AssignmentUploads\UploadedFileInspector;
use App\Services\AssignmentUploads\UploadRejectedException;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsUploadFixtures;
use Tests\TestCase;

/**
 * Round 3: the compound-file reader (sector sizes, DIFAT, fragmented directory, mini streams, name spellings), legacy
 * PowerPoint VBA detection through the record tree, and the fail-closed OOXML relationship scan.
 */
class AssignmentUploadsRound3Test extends TestCase
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
        $this->fail("Expected [{$file->getClientOriginalName()}] to be rejected with [{$reason}].");
    }

    private function tmp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'r3');
        file_put_contents($path, $bytes);

        return $path;
    }

    // ── CompoundFileReader on real container layouts ───────────────────────────────────────────────────────

    public function test_reader_handles_512_byte_sectors_with_regular_and_mini_streams(): void
    {
        $big = str_repeat('B', 9000);
        $path = $this->tmp($this->buildCfb(['WordDocument' => $big, 'small' => str_repeat('a', 100), 'tiny' => 'xyz']));

        $names = CompoundFileReader::entryNames($path);
        $this->assertEqualsCanonicalizing(['root entry', 'worddocument', 'small', 'tiny'], $names);
        $this->assertSame($big, CompoundFileReader::readStream($path, 'worddocument', 1048576));
        $this->assertSame(str_repeat('a', 100), CompoundFileReader::readStream($path, 'small', 1048576));
        $this->assertSame('xyz', CompoundFileReader::readStream($path, 'tiny', 1048576));
        $this->assertNull(CompoundFileReader::readStream($path, 'missing', 1048576));
        @unlink($path);
    }

    public function test_reader_handles_4096_byte_sectors_version_4(): void
    {
        $big = random_bytes(9000);
        $path = $this->tmp($this->buildCfb(['WordDocument' => $big, 'small' => 'hello'], ['sector_shift' => 12]));

        $this->assertContains('worddocument', CompoundFileReader::entryNames($path));
        $this->assertSame($big, CompoundFileReader::readStream($path, 'worddocument', 1048576));
        $this->assertSame('hello', CompoundFileReader::readStream($path, 'small', 1048576));
        @unlink($path);

        $accepted = $this->inspect($this->upload('v4.doc', $this->buildCfb(['WordDocument' => $big], ['sector_shift' => 12])));
        $this->assertSame('doc', $accepted->extension);
    }

    public function test_reader_follows_a_non_contiguous_directory_chain(): void
    {
        $streams = ['WordDocument' => str_repeat('q', 5000)];
        foreach (range(1, 14) as $i) {
            $streams["Stream{$i}"] = "data{$i}";
        }
        $path = $this->tmp($this->buildCfb($streams, ['reverse_directory' => true]));

        $names = CompoundFileReader::entryNames($path);
        foreach (array_keys($streams) as $name) {
            $this->assertContains(strtolower($name), $names);
        }
        $this->assertSame('data9', CompoundFileReader::readStream($path, 'stream9', 1048576));
        @unlink($path);

        // The same layout with a macro storage is still found.
        $streams['Macros'] = 'x';
        $this->assertRejected($this->upload('f.doc', $this->buildCfb($streams, ['reverse_directory' => true])), 'macros');
    }

    public function test_reader_follows_the_difat_when_more_than_109_fat_sectors_are_needed(): void
    {
        $big = str_repeat('Z', 7300000); // ~14.3k sectors of 512 bytes => 112 FAT sectors > the 109 the header holds
        $bytes = $this->buildCfb(['WordDocument' => $big]);
        $this->assertGreaterThan(109, unpack('V', substr($bytes, 0x2C, 4))[1], 'fixture really needs a DIFAT');
        $this->assertGreaterThan(0, unpack('V', substr($bytes, 0x48, 4))[1]);

        $path = $this->tmp($bytes);
        $this->assertContains('worddocument', CompoundFileReader::entryNames($path));
        $read = CompoundFileReader::readStream($path, 'worddocument', 10 * 1048576);
        $this->assertSame(strlen($big), strlen($read));
        $this->assertSame(md5($big), md5($read));
        @unlink($path);

        $this->assertSame('doc', $this->inspect($this->upload('huge.doc', $bytes))->extension);
    }

    public function test_reader_refuses_streams_over_the_cap_and_loops(): void
    {
        $path = $this->tmp($this->buildCfb(['WordDocument' => str_repeat('x', 6000)]));
        try {
            CompoundFileReader::readStream($path, 'worddocument', 1000);
            $this->fail('expected a RuntimeException for a stream over the cap');
        } catch (\RuntimeException $e) {
            $this->assertSame('stream too large', $e->getMessage());
        }
        @unlink($path);
    }

    public function test_directory_names_are_read_both_by_declared_length_and_up_to_the_first_nul(): void
    {
        // Declared length says "empty name", but the 64-byte field spells "Macros": the field reading catches it.
        $bytes = $this->cfbBytes(['WordDocument', 'Macros']);
        $bytes = substr_replace($bytes, pack('v', 2), 1024 + 2 * 128 + 0x40, 2);
        $this->assertContains('macros', CompoundFileReader::entryNames($this->tmp($bytes)));
        $this->assertRejected($this->upload('hidden.doc', $bytes), 'macros');

        // An embedded NUL inside the declared length ("Mac\0ros") is stripped by the declared reading.
        $bytes = $this->cfbBytes(['WordDocument', 'Mac?ros']);
        $bytes = substr_replace($bytes, str_repeat(chr(0), 2), 1024 + 2 * 128 + 3 * 2, 2);
        $this->assertContains('macros', CompoundFileReader::entryNames($this->tmp($bytes)));
        $this->assertRejected($this->upload('nul.doc', $bytes), 'macros');

        // Upper-case spellings fold.
        $this->assertRejected($this->upload('case.xls', $this->oleBytes('Workbook', ['_VBA_PROJECT_CUR'])), 'macros');
    }

    // ── Legacy PowerPoint: VBA lives in the record tree ────────────────────────────────────────────────────

    private function exOleObjStg(string $content, bool $compressed): string
    {
        $payload = $compressed ? pack('V', strlen($content)).gzcompress($content) : $content;

        return $this->pptRecord(0x1011, $payload, 0, $compressed ? 1 : 0);
    }

    private function vbaProjectBytes(): string
    {
        return str_repeat(chr(0), 64).mb_convert_encoding('_VBA_PROJECT', 'UTF-16LE', 'UTF-8').str_repeat(chr(0), 64);
    }

    public function test_a_clean_ppt_is_accepted_whether_its_stream_is_regular_or_mini(): void
    {
        $this->assertSame('ppt', $this->inspect($this->upload('ok.ppt', $this->pptFile($this->pptStream())))->extension);
        // Under 4096 bytes the stream lives in the mini stream.
        $this->assertSame('ppt', $this->inspect($this->upload('mini.ppt', $this->pptFile($this->pptStream(), false)))->extension);
        // An embedded OLE object that is NOT a VBA project is an ordinary embedding, not a macro.
        $clean = $this->pptFile($this->pptStream($this->exOleObjStg(str_repeat('picture', 50), true)));
        $this->assertSame('ppt', $this->inspect($this->upload('embed.ppt', $clean))->extension);
    }

    public function test_ppt_with_a_vba_info_container_is_rejected(): void
    {
        $vba = $this->pptRecord(0x03FF, $this->pptRecord(0x0400, pack('V3', 1, 1, 2)), 0xF);

        $this->assertRejected($this->upload('m1.ppt', $this->pptFile($this->pptStream($vba))), 'macros');
        $this->assertRejected($this->upload('m2.ppt', $this->pptFile($this->pptStream('', $vba))), 'macros'); // top level
        $this->assertRejected($this->upload('m3.ppt', $this->pptFile($this->pptStream($vba), false)), 'macros'); // mini stream
        $this->assertRejected($this->upload('m4.ppt', $this->pptFile($this->pptStream($this->pptRecord(0x0400, pack('V3', 0, 0, 2))))), 'macros'); // bare atom
    }

    public function test_ppt_with_a_vba_project_inside_an_ole_object_record_is_rejected(): void
    {
        $this->assertRejected($this->upload('c.ppt', $this->pptFile($this->pptStream($this->exOleObjStg($this->vbaProjectBytes(), true)))), 'macros');
        $this->assertRejected($this->upload('r.ppt', $this->pptFile($this->pptStream($this->exOleObjStg($this->vbaProjectBytes(), false)))), 'macros');
    }

    public function test_ppt_with_an_unparseable_record_tree_fails_closed(): void
    {
        // A record that claims more bytes than its parent holds.
        $overrun = $this->pptRecord(0x03E8, '', 0xF);
        $overrun = substr($overrun, 0, 4).pack('V', 99999).'abc';
        $this->assertRejected($this->upload('o.ppt', $this->pptFile($overrun)), 'unscannable');

        // An OLE object record whose zlib payload is garbage cannot be proven clean.
        $this->assertRejected($this->upload('g.ppt', $this->pptFile($this->pptStream($this->pptRecord(0x1011, 'garbage-not-zlib', 0, 1)))), 'unscannable');

        // Containers nested deeper than any real presentation.
        $nested = $this->pptRecord(0x03EE, 'x');
        for ($i = 0; $i < 40; $i++) {
            $nested = $this->pptRecord(0x03EE, $nested, 0xF);
        }
        $this->assertRejected($this->upload('d.ppt', $this->pptFile($nested)), 'unscannable');

        // Trailing bytes that are not a record header.
        $this->assertRejected($this->upload('t.ppt', $this->pptFile($this->pptStream().'junk', false)), 'unscannable');
    }

    public function test_ppt_stream_over_the_scan_cap_fails_closed(): void
    {
        $file = $this->buildCfb(['PowerPoint Document' => $this->pptStream().str_repeat(chr(0), 10 * 1048576 + 1)]);
        $path = $this->tmp($file);
        try {
            CompoundFileReader::readStream($path, 'powerpoint document', 10 * 1048576);
            $this->fail('expected the cap to be enforced');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        @unlink($path);
    }

    // ── OOXML relationship scan fails closed ───────────────────────────────────────────────────────────────

    private function rels(string $type, string $target, bool $external = true): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'.$type.'" Target="'.$target.'"'.($external ? ' TargetMode="External"' : '').'/></Relationships>';
    }

    private function docxWith(array $extra, ?string $contentTypes = null): string
    {
        return $this->ooxmlBytes('word', true, $extra, $contentTypes);
    }

    public function test_hiding_an_evil_relationship_behind_1000_padding_parts_is_rejected(): void
    {
        $extra = [];
        for ($i = 1; $i <= 1001; $i++) {
            $extra["word/_rels/pad{$i}.xml.rels"] = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>';
        }
        $extra['word/_rels/zz-last.xml.rels'] = $this->rels('attachedTemplate', 'http://evil.example/t.dotm');

        $this->assertRejected($this->upload('pad.docx', $this->docxWith($extra)), 'rels_limit');
    }

    public function test_a_normal_document_with_about_ten_relationship_parts_is_accepted(): void
    {
        $extra = [];
        for ($i = 1; $i <= 10; $i++) {
            $extra["word/_rels/part{$i}.xml.rels"] = $this->rels('hyperlink', 'https://example.com/'.$i);
        }
        $extra['word/_rels/document.xml.rels'] = $this->rels('image', 'media/image1.png', false);

        $this->assertSame('docx', $this->inspect($this->upload('ok.docx', $this->docxWith($extra)))->extension);
    }

    public function test_oversized_relationship_parts_and_budget_are_distinct_rejections(): void
    {
        $pad = fn (int $bytes) => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.str_repeat(' ', $bytes).'</Relationships>';

        $this->assertRejected($this->upload('big.docx', $this->docxWith(['word/_rels/document.xml.rels' => $pad(300000)])), 'rels_too_large');

        $extra = [];
        for ($i = 1; $i <= 25; $i++) { // 25 x 200 KB = 5 MB, each part individually fine
            $extra["word/_rels/p{$i}.xml.rels"] = $pad(200000);
        }
        $this->assertRejected($this->upload('budget.docx', $this->docxWith($extra)), 'rels_budget');
    }

    public function test_only_relationship_parts_inside_a_rels_folder_are_consulted(): void
    {
        // Office never reads a *.rels outside a `_rels` folder, so it cannot pull anything in either.
        $this->assertSame('docx', $this->inspect($this->upload('x.docx', $this->docxWith(['word/stray.rels' => $this->rels('attachedTemplate', 'http://evil.example/t')])))->extension);
    }

    public static function externalTypes(): array
    {
        return array_map(fn ($t) => [$t], ['image', 'package', 'video', 'audio', 'oleObject', 'attachedTemplate', 'frame', 'subDocument', 'externalLink', 'someFutureType']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('externalTypes')]
    public function test_every_external_relationship_type_except_hyperlink_is_rejected(string $type): void
    {
        $bytes = $this->docxWith(['word/_rels/document.xml.rels' => $this->rels($type, 'http://evil.example/x')]);

        $this->assertRejected($this->upload('e.docx', $bytes), 'external_relationship');
    }

    public function test_plain_hyperlinks_are_the_only_external_relationship_allowed(): void
    {
        foreach (['https://example.com/a', 'http://example.com/', 'mailto:someone@example.com'] as $target) {
            $bytes = $this->docxWith(['word/_rels/document.xml.rels' => $this->rels('hyperlink', $target)]);
            $this->assertSame('docx', $this->inspect($this->upload('h.docx', $bytes))->extension, $target);
        }

        // ...but only to web / mail schemes.
        foreach (['file:///C:/secret.txt', 'javascript:alert(1)', 'ftp://example.com/x', '\\\\server\\share\\x'] as $target) {
            $bytes = $this->docxWith(['word/_rels/document.xml.rels' => $this->rels('hyperlink', $target)]);
            $this->assertRejected($this->upload('bad.docx', $bytes), 'external_relationship');
        }
    }

    public function test_a_doctype_in_a_relationship_part_or_content_types_is_rejected(): void
    {
        $doctype = '<?xml version="1.0"?><!DOCTYPE Relationships [<!ENTITY x "y">]><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>';
        $this->assertRejected($this->upload('d1.docx', $this->docxWith(['word/_rels/document.xml.rels' => $doctype])), 'doctype_forbidden');

        $types = '<?xml version="1.0"?><!DOCTYPE Types [<!ENTITY x "y">]><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>';
        $this->assertRejected($this->upload('d2.docx', $this->docxWith([], $types)), 'doctype_forbidden');
    }

    public function test_percent_encoded_part_names_cannot_hide_vba_activex_executables_or_traversal(): void
    {
        $this->assertRejected($this->upload('a.docx', $this->docxWith(['word/vba%50roject.bin' => 'x'])), 'macros');
        $this->assertRejected($this->upload('b.docx', $this->docxWith(['word/%61ctiveX/a1.xml' => '<x/>'])), 'active_content');
        $this->assertRejected($this->upload('c.docx', $this->docxWith(['word/embeddings/run%2eexe' => 'MZ'])), 'embedded_executable');
        $this->assertRejected($this->upload('d.docx', $this->docxWith(['word/%2e%2e/%2e%2e/evil.xml' => 'x'])), 'zip_unsafe_path');
        $this->assertRejected($this->upload('e.zip', $this->zipBytes(['%2e%2e/evil.txt' => 'x'])), 'zip_unsafe_path');
        $this->assertRejected($this->upload('f.zip', $this->zipBytes(['a%00b.txt' => 'x'])), 'zip_unsafe_path');
        // Ordinary literal percent signs in a file name are fine.
        $this->assertSame('zip', $this->inspect($this->upload('g.zip', $this->zipBytes(['100%.txt' => 'x'])))->extension);
    }
}
