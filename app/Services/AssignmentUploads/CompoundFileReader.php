<?php

namespace App\Services\AssignmentUploads;

use RuntimeException;

/**
 * Reads the DIRECTORY (names of storages / streams) and, on request, ONE stream of an OLE2 / Compound File Binary
 * file, the container behind legacy .doc / .xls / .ppt (and also .msi, which is why a bare magic-number check is not
 * enough).
 *
 * Bounded by design: every read is an fseek/fread of one sector, the file is never loaded as a whole, the number of
 * directory sectors and FAT sectors is capped, chains are loop-checked, sector numbers are checked against the file
 * size, and a stream is only returned when its declared size is within the caller's cap. Nothing in the file is
 * executed or extracted.
 */
final class CompoundFileReader
{
    private const MAGIC = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    private const FREE = 0xFFFFFFFF;

    private const END_OF_CHAIN = 0xFFFFFFFE;

    private const MAX_DIRECTORY_SECTORS = 4096;

    private const MAX_FAT_SECTORS = 8192;

    /** The mini stream container (root entry stream) is read whole; refuse absurd sizes. */
    private const MAX_MINI_CONTAINER = 16 * 1048576;

    /** @var resource */
    private $h;

    private int $fileSize;

    private int $sectorSize;

    private int $perFatSector;

    /** @var list<int> */
    private array $fat = [];

    private int $firstDir;

    private int $miniCutoff;

    private int $firstMiniFat;

    private int $numMiniFat;

    /** @var list<array{names:list<string>,type:int,start:int,size:int}>|null */
    private ?array $entries = null;

    /**
     * @return list<string> every spelling of every allocated storage / stream name (lower-case, see {@see variants()})
     *
     * @throws RuntimeException when the file is not a well-formed compound file
     */
    public static function entryNames(string $path): array
    {
        $reader = new self($path);
        try {
            $names = [];
            foreach ($reader->entries() as $entry) {
                array_push($names, ...$entry['names']);
            }

            return array_values(array_unique($names));
        } finally {
            $reader->close();
        }
    }

    /**
     * The bytes of the stream whose name (lower-case) is `$lowerName`, or null when no such stream exists.
     *
     * @throws RuntimeException when the container is malformed or the stream is larger than `$maxBytes`
     */
    public static function readStream(string $path, string $lowerName, int $maxBytes): ?string
    {
        $reader = new self($path);
        try {
            foreach ($reader->entries() as $entry) {
                if ($entry['type'] === 2 && in_array($lowerName, $entry['names'], true)) {
                    return $reader->streamBytes($entry, $maxBytes);
                }
            }

            return null;
        } finally {
            $reader->close();
        }
    }

    private function __construct(string $path)
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw new RuntimeException('unreadable');
        }
        $this->h = $h;

        try {
            $this->readHeader($path);
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    private function close(): void
    {
        if (is_resource($this->h)) {
            fclose($this->h);
        }
    }

    private function readHeader(string $path): void
    {
        $this->fileSize = (int) @filesize($path);
        $header = (string) fread($this->h, 512);
        if (strlen($header) < 512 || ! str_starts_with($header, self::MAGIC)) {
            throw new RuntimeException('not a compound file');
        }

        $byteOrder = unpack('v', substr($header, 0x1C, 2))[1];
        $shift = unpack('v', substr($header, 0x1E, 2))[1];
        if ($byteOrder !== 0xFFFE || ! in_array($shift, [9, 12], true)) {
            throw new RuntimeException('unsupported compound file header');
        }
        $this->sectorSize = 1 << $shift;
        $this->perFatSector = intdiv($this->sectorSize, 4);

        $numFat = unpack('V', substr($header, 0x2C, 4))[1];
        $this->firstDir = unpack('V', substr($header, 0x30, 4))[1];
        $this->miniCutoff = unpack('V', substr($header, 0x38, 4))[1];
        $this->firstMiniFat = unpack('V', substr($header, 0x3C, 4))[1];
        $this->numMiniFat = unpack('V', substr($header, 0x40, 4))[1];
        $firstDifat = unpack('V', substr($header, 0x44, 4))[1];
        $numDifat = unpack('V', substr($header, 0x48, 4))[1];

        if ($numFat < 1 || $numFat > self::MAX_FAT_SECTORS || $numDifat > 512 || $this->numMiniFat > 4096) {
            throw new RuntimeException('implausible FAT size');
        }

        // FAT sector ids: the first 109 live in the header, the rest in the DIFAT chain.
        for ($i = 0; $i < 109 && count($this->fat) < $numFat; $i++) {
            $sid = unpack('V', substr($header, 0x4C + $i * 4, 4))[1];
            if ($sid !== self::FREE) {
                $this->fat[] = $sid;
            }
        }
        $difat = $firstDifat;
        for ($n = 0; $n < $numDifat && count($this->fat) < $numFat; $n++) {
            $sector = $this->readSector($difat);
            for ($i = 0; $i < $this->perFatSector - 1 && count($this->fat) < $numFat; $i++) {
                $sid = unpack('V', substr($sector, $i * 4, 4))[1];
                if ($sid !== self::FREE) {
                    $this->fat[] = $sid;
                }
            }
            $difat = unpack('V', substr($sector, $this->sectorSize - 4, 4))[1];
        }
    }

    private function inFile(int $sid): bool
    {
        return $sid < self::END_OF_CHAIN && ($sid + 1) * $this->sectorSize < $this->fileSize;
    }

    private function readSector(int $sid): string
    {
        if (! $this->inFile($sid)) {
            throw new RuntimeException('sector outside the file');
        }
        fseek($this->h, ($sid + 1) * $this->sectorSize);
        $sector = (string) fread($this->h, $this->sectorSize);
        if (strlen($sector) < $this->sectorSize) {
            throw new RuntimeException('truncated sector');
        }

        return $sector;
    }

    private function nextSector(int $sid): int
    {
        $fatSector = $this->fat[intdiv($sid, $this->perFatSector)] ?? null;
        if ($fatSector === null || ! $this->inFile($fatSector)) {
            throw new RuntimeException('FAT sector missing');
        }
        fseek($this->h, ($fatSector + 1) * $this->sectorSize + ($sid % $this->perFatSector) * 4);
        $raw = (string) fread($this->h, 4);
        if (strlen($raw) < 4) {
            throw new RuntimeException('truncated FAT');
        }

        return unpack('V', $raw)[1];
    }

    /** @return list<array{names:list<string>,type:int,start:int,size:int}> */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];
        $seen = [];
        $sid = $this->firstDir;
        while ($sid !== self::END_OF_CHAIN) {
            if (isset($seen[$sid]) || count($seen) >= self::MAX_DIRECTORY_SECTORS) {
                throw new RuntimeException('bad directory chain');
            }
            $seen[$sid] = true;
            $sector = $this->readSector($sid);

            for ($e = 0; $e + 128 <= $this->sectorSize; $e += 128) {
                $type = ord($sector[$e + 0x42]);
                if ($type === 0) {
                    continue; // unallocated
                }
                $size = unpack('V', substr($sector, $e + 0x78, 4))[1] + (unpack('V', substr($sector, $e + 0x7C, 4))[1] << 32);
                $entries[] = [
                    'names' => self::variants(substr($sector, $e, 64), unpack('v', substr($sector, $e + 0x40, 2))[1]),
                    'type'  => $type,
                    'start' => unpack('V', substr($sector, $e + 0x74, 4))[1],
                    'size'  => $size,
                ];
            }

            $sid = $this->nextSector($sid);
        }

        return $this->entries = $entries;
    }

    /**
     * Both readings of a directory name, lower-cased with NULs removed: the declared length (minus the UTF-16
     * terminator) and the whole 64-byte field up to its first NUL code unit. A name that disagrees with its own
     * declared length cannot hide from either reading.
     *
     * @return list<string>
     */
    public static function variants(string $field, int $declaredBytes): array
    {
        $field = str_pad(substr($field, 0, 64), 64, "\0");
        $declared = substr($field, 0, max(0, min(64, $declaredBytes) - 2));

        $untilNul = '';
        for ($i = 0; $i + 1 < 64; $i += 2) {
            if ($field[$i] === "\0" && $field[$i + 1] === "\0") {
                break;
            }
            $untilNul .= $field[$i].$field[$i + 1];
        }

        $out = [];
        foreach ([$declared, $untilNul] as $raw) {
            $name = mb_strtolower(str_replace("\0", '', (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE')));
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /** @param array{names:list<string>,type:int,start:int,size:int} $entry */
    private function streamBytes(array $entry, int $maxBytes): string
    {
        if ($entry['size'] > $maxBytes) {
            throw new RuntimeException('stream too large');
        }
        if ($entry['size'] === 0) {
            return '';
        }

        if ($entry['size'] < $this->miniCutoff) {
            return $this->readMini($entry['start'], $entry['size']);
        }

        return $this->readRegular($entry['start'], $entry['size']);
    }

    private function readRegular(int $start, int $size): string
    {
        $sectors = (int) ceil($size / $this->sectorSize);
        $out = '';
        $sid = $start;
        $seen = [];
        for ($i = 0; $i < $sectors; $i++) {
            if (isset($seen[$sid])) {
                throw new RuntimeException('loop in stream chain');
            }
            $seen[$sid] = true;
            $out .= $this->readSector($sid);
            if ($i < $sectors - 1) {
                $sid = $this->nextSector($sid);
                if ($sid >= self::END_OF_CHAIN) {
                    throw new RuntimeException('stream chain ends early');
                }
            }
        }

        return substr($out, 0, $size);
    }

    private function readMini(int $start, int $size): string
    {
        $root = null;
        foreach ($this->entries() as $candidate) {
            if ($candidate['type'] === 5) {
                $root = $candidate;
                break;
            }
        }
        if ($root === null || $root['size'] > self::MAX_MINI_CONTAINER || $this->numMiniFat < 1) {
            throw new RuntimeException('mini stream unavailable');
        }

        $container = $this->readRegular($root['start'], $root['size']);
        $miniFatBytes = $this->readRegular($this->firstMiniFat, $this->numMiniFat * $this->sectorSize);
        $miniFat = array_values(unpack('V*', $miniFatBytes) ?: []);

        $miniSectors = (int) ceil($size / 64);
        $out = '';
        $sid = $start;
        $seen = [];
        for ($i = 0; $i < $miniSectors; $i++) {
            if (isset($seen[$sid]) || $sid >= self::END_OF_CHAIN || ($sid + 1) * 64 > strlen($container)) {
                throw new RuntimeException('bad mini stream chain');
            }
            $seen[$sid] = true;
            $out .= substr($container, $sid * 64, 64);
            if ($i < $miniSectors - 1) {
                $sid = $miniFat[$sid] ?? self::END_OF_CHAIN;
            }
        }

        return substr($out, 0, $size);
    }
}
