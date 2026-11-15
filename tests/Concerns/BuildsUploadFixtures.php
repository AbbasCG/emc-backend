<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Minimal files whose BYTES really are what their name claims (or deliberately are not). Everything is built in
 * memory/temp; nothing here depends on the file extension being trusted.
 */
trait BuildsUploadFixtures
{
    protected function upload(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    protected function jpgBytes(): string
    {
        return $this->gdBytes('imagejpeg');
    }

    protected function pngBytes(): string
    {
        return $this->gdBytes('imagepng');
    }

    protected function webpBytes(): string
    {
        return $this->gdBytes('imagewebp');
    }

    private function gdBytes(string $fn): string
    {
        $im = imagecreatetruecolor(8, 8);
        ob_start();
        $fn($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    protected function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /** A real OOXML package: zip with [Content_Types].xml and the given top-level folder. */
    protected function ooxmlBytes(string $folder = 'word', bool $withContentTypes = true, array $extra = [], ?string $contentTypesXml = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ooxml');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);
        if ($withContentTypes) {
            $zip->addFromString('[Content_Types].xml', $contentTypesXml ?? '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        }
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships/>');
        $zip->addFromString($folder.'/document.xml', '<doc>hello</doc>');
        foreach ($extra as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** A plain zip archive with the given entries (name => content). */
    protected function zipBytes(array $entries = ['readme.txt' => 'hello']): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** OLE2 compound file whose DIRECTORY names a stream, as .doc/.xls/.ppt do (see cfbBytes). */
    protected function oleBytes(string $streamName, array $extraNames = []): string
    {
        return $this->cfbBytes(array_merge([$streamName], $extraNames));
    }

    /**
     * A genuine, minimal Compound File Binary: header, one FAT sector and as many directory sectors as needed. Every
     * given name becomes an (empty) stream entry under the root. No stream data is needed because the validator only
     * reads the directory.
     *
     * @param list<string> $names
     */
    protected function cfbBytes(array $names): string
    {
        $zero = chr(0);
        $entries = array_merge([['Root Entry', 5]], array_map(fn ($n) => [$n, 2], $names));
        $dirSectors = (int) ceil(count($entries) / 4);
        $free = pack('V', 0xFFFFFFFF);

        $header = hex2bin('D0CF11E0A1B11AE1').str_repeat($zero, 16)
            .pack('v', 0x3E).pack('v', 3).pack('v', 0xFFFE).pack('v', 9).pack('v', 6).str_repeat($zero, 6)
            .pack('V', 0).pack('V', 1).pack('V', 1).pack('V', 0).pack('V', 4096)
            .pack('V', 0xFFFFFFFE).pack('V', 0).pack('V', 0xFFFFFFFE).pack('V', 0)
            .pack('V', 0).str_repeat($free, 108);

        // FAT: sector 0 is the FAT itself, 1..N the directory chain.
        $fat = pack('V', 0xFFFFFFFD);
        for ($i = 1; $i <= $dirSectors; $i++) {
            $fat .= pack('V', $i === $dirSectors ? 0xFFFFFFFE : $i + 1);
        }
        $fat = str_pad($fat, 512, $free);

        $dir = '';
        foreach ($entries as [$name, $type]) {
            $utf16 = mb_convert_encoding($name, 'UTF-16LE', 'UTF-8');
            $dir .= str_pad($utf16, 64, $zero).pack('v', strlen($utf16) + 2).chr($type).chr(1)
                .$free.$free.$free
                .str_repeat($zero, 16).pack('V', 0).str_repeat($zero, 16)
                .pack('V', 0xFFFFFFFE).str_repeat($zero, 8);
        }

        return $header.$fat.str_pad($dir, $dirSectors * 512, $zero);
    }

    /**
     * A general Compound File Binary writer for reader / PowerPoint tests: real header, DIFAT (when more than 109 FAT
     * sectors are needed), FAT, directory, mini FAT + mini stream (streams under 4096 bytes) and regular streams.
     *
     * @param array<string,string> $streams name => bytes, all directly under the root entry
     * @param array{sector_shift?:int, reverse_directory?:bool} $opts sector_shift 9 (512 bytes, v3) or 12 (4096 bytes, v4);
     *                                                                reverse_directory chains the directory sectors in
     *                                                                descending sector order (non-contiguous chain)
     */
    protected function buildCfb(array $streams, array $opts = []): string
    {
        $shift = $opts['sector_shift'] ?? 9;
        $sectorSize = 1 << $shift;
        $perFat = intdiv($sectorSize, 4);
        $zero = chr(0);
        $free = 0xFFFFFFFF;
        $end = 0xFFFFFFFE;
        $cutoff = 4096;

        // Split into mini-stream residents and regular streams.
        $mini = '';
        $miniFat = [];
        $meta = []; // name => [start, size, big, bytes]
        foreach ($streams as $name => $bytes) {
            if (strlen($bytes) < $cutoff) {
                $start = intdiv(strlen($mini), 64);
                $n = max(1, (int) ceil(strlen($bytes) / 64));
                for ($i = 0; $i < $n; $i++) {
                    $miniFat[] = $i === $n - 1 ? $end : $start + $i + 1;
                }
                $mini .= str_pad($bytes, $n * 64, $zero);
                $meta[$name] = [$start, strlen($bytes), false, $bytes];
            } else {
                $meta[$name] = [null, strlen($bytes), true, $bytes];
            }
        }

        $dirEntries = 1 + count($streams);
        $dirSectors = (int) ceil($dirEntries / intdiv($sectorSize, 128));
        $miniContainerSectors = $mini === '' ? 0 : (int) ceil(strlen($mini) / $sectorSize);
        $miniFatSectors = $miniFat === [] ? 0 : (int) ceil(count($miniFat) * 4 / $sectorSize);
        $bigSectors = 0;
        foreach ($meta as [, $size, $big]) {
            $bigSectors += $big ? (int) ceil($size / $sectorSize) : 0;
        }
        $base = $dirSectors + $miniContainerSectors + $miniFatSectors + $bigSectors;

        // Number of FAT sectors F (and DIFAT sectors) needed to describe every sector including themselves.
        for ($fatSectors = 1;; $fatSectors++) {
            $difatSectors = $fatSectors > 109 ? (int) ceil(($fatSectors - 109) / ($perFat - 1)) : 0;
            if ($fatSectors * $perFat >= $fatSectors + $difatSectors + $base) {
                break;
            }
        }

        $next = $fatSectors + $difatSectors;
        $take = function (int $count) use (&$next): array {
            $ids = $count > 0 ? range($next, $next + $count - 1) : [];
            $next += $count;

            return $ids;
        };
        $dirIds = $take($dirSectors);
        $miniFatIds = $take($miniFatSectors);
        $containerIds = $take($miniContainerSectors);
        $bigIds = [];
        foreach ($meta as $name => [, $size, $big]) {
            $bigIds[$name] = $big ? $take((int) ceil($size / $sectorSize)) : [];
        }

        $dirChain = ! empty($opts['reverse_directory']) ? array_reverse($dirIds) : $dirIds;

        $fat = array_fill(0, $fatSectors * $perFat, $free);
        for ($i = 0; $i < $fatSectors; $i++) {
            $fat[$i] = 0xFFFFFFFD;
        }
        for ($i = 0; $i < $difatSectors; $i++) {
            $fat[$fatSectors + $i] = 0xFFFFFFFC;
        }
        $chain = function (array $ids) use (&$fat, $end): void {
            foreach ($ids as $i => $id) {
                $fat[$id] = $ids[$i + 1] ?? $end;
            }
        };
        $chain($dirChain);
        $chain($miniFatIds);
        $chain($containerIds);
        foreach ($bigIds as $ids) {
            $chain($ids);
        }

        // Directory.
        $entry = function (string $name, int $type, int $start, int $size) use ($zero, $free): string {
            $utf16 = mb_convert_encoding($name, 'UTF-16LE', 'UTF-8');

            return str_pad($utf16, 64, $zero).pack('v', strlen($utf16) + 2).chr($type).chr(1)
                .pack('V', $free).pack('V', $free).pack('V', $free)
                .str_repeat($zero, 16).pack('V', 0).str_repeat($zero, 16)
                .pack('V', $start).pack('V', $size).pack('V', 0);
        };
        $dir = $entry('Root Entry', 5, $containerIds[0] ?? $end, strlen($mini));
        foreach ($meta as $name => [$start, $size, $big]) {
            $dir .= $entry($name, 2, $big ? $bigIds[$name][0] : $start, $size);
        }
        $dir = str_pad($dir, $dirSectors * $sectorSize, $zero);

        // Header (padded to one whole sector).
        $fatIds = range(0, $fatSectors - 1);
        $headerFat = '';
        for ($i = 0; $i < 109; $i++) {
            $headerFat .= pack('V', $fatIds[$i] ?? $free);
        }
        $header = hex2bin('D0CF11E0A1B11AE1').str_repeat($zero, 16)
            .pack('v', 0x3E).pack('v', $shift === 9 ? 3 : 4).pack('v', 0xFFFE).pack('v', $shift).pack('v', 6).str_repeat($zero, 6)
            .pack('V', $shift === 9 ? 0 : $dirSectors).pack('V', $fatSectors).pack('V', $dirChain[0]).pack('V', 0).pack('V', $cutoff)
            .pack('V', $miniFatIds[0] ?? $end).pack('V', $miniFatSectors)
            .pack('V', $difatSectors > 0 ? $fatSectors : $end).pack('V', $difatSectors)
            .$headerFat;
        $out = str_pad($header, $sectorSize, $zero);

        // FAT sectors.
        $fatBytes = pack('V*', ...$fat);
        $out .= $fatBytes;

        // DIFAT sectors: the FAT sector ids beyond the first 109, then a link to the next DIFAT sector.
        $rest = array_slice($fatIds, 109);
        for ($d = 0; $d < $difatSectors; $d++) {
            $slice = array_slice($rest, $d * ($perFat - 1), $perFat - 1);
            $sector = '';
            for ($i = 0; $i < $perFat - 1; $i++) {
                $sector .= pack('V', $slice[$i] ?? $free);
            }
            $out .= $sector.pack('V', $d === $difatSectors - 1 ? $end : $fatSectors + $d + 1);
        }

        // Directory (in id order; the FAT chain decides the logical order), mini FAT, mini container, regular streams.
        $byId = [];
        foreach ($dirChain as $i => $id) {
            $byId[$id] = substr($dir, $i * $sectorSize, $sectorSize);
        }
        if ($miniFatSectors > 0) {
            $mf = str_pad(pack('V*', ...$miniFat), $miniFatSectors * $sectorSize, pack('V', $free));
            foreach ($miniFatIds as $i => $id) {
                $byId[$id] = substr($mf, $i * $sectorSize, $sectorSize);
            }
        }
        foreach ($containerIds as $i => $id) {
            $byId[$id] = str_pad(substr($mini, $i * $sectorSize, $sectorSize), $sectorSize, $zero);
        }
        foreach ($meta as $name => [, , $big, $bytes]) {
            if (! $big) {
                continue;
            }
            foreach ($bigIds[$name] as $i => $id) {
                $byId[$id] = str_pad(substr($bytes, $i * $sectorSize, $sectorSize), $sectorSize, $zero);
            }
        }
        ksort($byId);

        return $out.implode('', $byId);
    }

    /** One PowerPoint binary record: 8-byte header (recVer | recInstance<<4, recType, recLen) + body. */
    protected function pptRecord(int $type, string $body = '', int $version = 0, int $instance = 0): string
    {
        return pack('v', ($instance << 4) | $version).pack('v', $type).pack('V', strlen($body)).$body;
    }

    /** A plausible `PowerPoint Document` stream: a Document container with atoms and a slide container. */
    protected function pptStream(string $extraInsideDocument = '', string $extraTopLevel = ''): string
    {
        $document = $this->pptRecord(0x03E8, $this->pptRecord(0x03E9, str_repeat(chr(0), 40)).$extraInsideDocument, 0xF);
        $slide = $this->pptRecord(0x03EE, $this->pptRecord(0x03F3, str_repeat(chr(0), 24)), 0xF);

        return $document.$slide.$extraTopLevel;
    }

    /** Wraps a stream into a .ppt compound file (`PowerPoint Document` + `Current User`), zero-padded past the mini-stream cutoff. */
    protected function pptFile(string $stream, bool $padToRegularStream = true): string
    {
        if ($padToRegularStream) {
            $stream = str_pad($stream, max(5000, strlen($stream)), chr(0));
        }

        return $this->buildCfb(['PowerPoint Document' => $stream, 'Current User' => str_repeat('c', 64)]);
    }

    protected function rarBytes(): string
    {
        return "Rar!\x1A\x07\x00".str_repeat("\0", 64);
    }

    protected function sevenZipBytes(): string
    {
        return "7z\xBC\xAF\x27\x1C\x00\x04".str_repeat("\0", 64);
    }

    /** A Windows program (valid DOS stub + PE header). */
    protected function exeBytes(): string
    {
        $head = "MZ\x90\x00".str_repeat("\0", 56)."\x80\x00\x00\x00";
        $head .= str_repeat("\0", 0x80 - strlen($head))."PE\0\0".str_repeat("\0", 100);

        return $head;
    }
}
