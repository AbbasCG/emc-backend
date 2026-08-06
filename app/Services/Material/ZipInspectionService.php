<?php

namespace App\Services\Material;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class ZipInspectionService
{
    /** Inspect a ZIP without extracting it. Throws ValidationException on any threat. */
    public function inspect(UploadedFile $file): void
    {
        if (! config('materials.zip.inspect', true)) {
            return;
        }

        $zip = new ZipArchive();
        $result = $zip->open($file->getRealPath());

        if ($result !== true) {
            throw ValidationException::withMessages([
                'file' => ['ملف ZIP غير صالح أو تالف.'],
            ]);
        }

        $dangerous  = config('materials.zip.dangerous_extensions', []);
        $maxRatio   = config('materials.zip.max_ratio', 100);
        $maxBytes   = config('materials.zip.max_uncompressed_bytes', 2 * 1024 * 1024 * 1024);
        $compressed = max(1, $file->getSize());
        $total      = 0;

        for ($i = 0; $i < $zip->count(); $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $name = $stat['name'];
            $total += (int) ($stat['size'] ?? 0);

            // ── Path traversal ────────────────────────────────────────────────
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
                $zip->close();
                throw ValidationException::withMessages([
                    'file' => ['تم اكتشاف هجوم اجتياز مسار داخل الأرشيف.'],
                ]);
            }

            // ── Dangerous extension ───────────────────────────────────────────
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== '' && in_array($ext, $dangerous, true)) {
                $zip->close();
                throw ValidationException::withMessages([
                    'file' => ["الأرشيف يحتوي على ملف خطير: .{$ext}"],
                ]);
            }

            // ── Nested archive (simple check) ─────────────────────────────────
            if (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'], true)) {
                $zip->close();
                throw ValidationException::withMessages([
                    'file' => ['الأرشيف يحتوي على أرشيف متداخل — غير مسموح به.'],
                ]);
            }

            // ── ZIP bomb: per-entry ratio check ───────────────────────────────
            $entryCompressed   = max(1, (int) ($stat['comp_size'] ?? 1));
            $entryUncompressed = (int) ($stat['size'] ?? 0);
            if ($entryUncompressed > 0 && ($entryUncompressed / $entryCompressed) > $maxRatio) {
                $zip->close();
                throw ValidationException::withMessages([
                    'file' => ['تم اكتشاف قنبلة ZIP (نسبة ضغط مشبوهة).'],
                ]);
            }
        }

        $zip->close();

        // ── Total uncompressed size limit ─────────────────────────────────────
        if ($total > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => ['المحتوى غير المضغوط للأرشيف يتجاوز الحد المسموح به.'],
            ]);
        }

        // ── Global ratio check ────────────────────────────────────────────────
        if ($total > 0 && ($total / $compressed) > $maxRatio) {
            throw ValidationException::withMessages([
                'file' => ['تم اكتشاف قنبلة ZIP (نسبة ضغط إجمالية مشبوهة).'],
            ]);
        }
    }
}
