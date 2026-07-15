<?php

namespace App\Jobs;

use App\Models\AmbassadorApplicationFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessAmbassadorFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const PDF_EXTENSION    = 'pdf';

    public function __construct(private readonly int $fileId)
    {
    }

    public function handle(): void
    {
        $fileRecord = AmbassadorApplicationFile::find($this->fileId);

        if (! $fileRecord) {
            Log::warning('ProcessAmbassadorFile: record not found', ['file_id' => $this->fileId]);
            return;
        }

        if (! Storage::disk($fileRecord->disk)->exists($fileRecord->path)) {
            $this->markFailed($fileRecord, 'الملف غير موجود على القرص.');
            return;
        }

        $fileRecord->update(['processing_status' => 'processing']);

        $ext = strtolower($fileRecord->extension);

        try {
            if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
                $this->processImage($fileRecord);
            } elseif ($ext === self::PDF_EXTENSION) {
                $this->processPdf($fileRecord);
            } else {
                $fileRecord->update(['processing_status' => 'skipped']);
            }
        } catch (\Throwable $e) {
            Log::error('ProcessAmbassadorFile: processing failed', [
                'file_id' => $this->fileId,
                'error'   => $e->getMessage(),
            ]);
            $this->markFailed($fileRecord, $e->getMessage());
        }
    }

    // ── Image processing ──────────────────────────────────────────────────────

    private function processImage(AmbassadorApplicationFile $fileRecord): void
    {
        $hasGd      = extension_loaded('gd');
        $hasImagick = extension_loaded('imagick');

        if (! $hasGd && ! $hasImagick) {
            $fileRecord->update(['processing_status' => 'skipped']);
            return;
        }

        $disk       = Storage::disk($fileRecord->disk);
        $sourcePath = $disk->path($fileRecord->path);
        $dir        = dirname($fileRecord->path);
        $baseName   = Str::uuid();

        if ($hasImagick) {
            $this->processImageWithImagick($fileRecord, $sourcePath, $dir, $baseName, $disk);
        } else {
            $this->processImageWithGd($fileRecord, $sourcePath, $dir, $baseName, $disk);
        }
    }

    private function processImageWithImagick(
        AmbassadorApplicationFile $fileRecord,
        string $sourcePath,
        string $dir,
        string $baseName,
        $disk
    ): void {
        $imagick = new \Imagick($sourcePath);
        $imagick->stripImage(); // strip EXIF

        // Auto-orient
        $imagick->autoOrient();

        // Main image: resize to max 2400px on longest side, keep original format
        $main = clone $imagick;
        $main->resizeImage(2400, 2400, \Imagick::FILTER_LANCZOS, 1, true); // bestfit

        $mainRelPath = $dir . '/' . $baseName . '_main.' . $fileRecord->extension;
        $mainAbsPath = $disk->path($mainRelPath);
        $main->writeImage($mainAbsPath);
        $main->destroy();

        // Thumbnail: 400x400 WEBP
        $thumb = clone $imagick;
        $thumb->resizeImage(400, 400, \Imagick::FILTER_LANCZOS, 1, true);
        $thumb->setImageFormat('webp');
        $thumb->setImageCompressionQuality(80);
        $thumbRelPath = $dir . '/' . $baseName . '_thumb.webp';
        $thumbAbsPath = $disk->path($thumbRelPath);
        $thumb->writeImage($thumbAbsPath);
        $thumb->destroy();

        $imagick->destroy();

        $mainExists    = file_exists($mainAbsPath);
        $optimizedSize = $mainExists ? filesize($mainAbsPath) : $fileRecord->original_size;

        // Only serve the optimized version if it's actually smaller than the original.
        if (! $mainExists || $optimizedSize >= $fileRecord->original_size) {
            if ($mainExists) @unlink($mainAbsPath);
            $fileRecord->update([
                'thumbnail_path'    => $thumbRelPath,
                'processing_status' => 'skipped',
            ]);
            return;
        }

        $ratio = $fileRecord->original_size > 0
            ? round((1 - $optimizedSize / $fileRecord->original_size) * 100, 2)
            : 0.0;

        $fileRecord->update([
            'optimized_size'    => $optimizedSize,
            'compression_ratio' => $ratio,
            'thumbnail_path'    => $thumbRelPath,
            // Optimized main image becomes the file served on preview/download.
            'preview_path'      => $mainRelPath,
            'processing_status' => 'optimized',
            'processed_at'      => now(),
        ]);
    }

    private function processImageWithGd(
        AmbassadorApplicationFile $fileRecord,
        string $sourcePath,
        string $dir,
        string $baseName,
        $disk
    ): void {
        $ext = strtolower($fileRecord->extension);

        $src = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($sourcePath),
            'png'         => @imagecreatefrompng($sourcePath),
            'webp'        => @imagecreatefromwebp($sourcePath),
            'gif'         => @imagecreatefromgif($sourcePath),
            default       => false,
        };

        if (! $src) {
            $fileRecord->update(['processing_status' => 'skipped']);
            return;
        }

        $origW = imagesx($src);
        $origH = imagesy($src);
        $maxDim = 2400;

        if ($origW > $maxDim || $origH > $maxDim) {
            $ratio = min($maxDim / $origW, $maxDim / $origH);
            $newW  = (int) round($origW * $ratio);
            $newH  = (int) round($origH * $ratio);
        } else {
            $newW = $origW;
            $newH = $origH;
        }

        $mainImg = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($mainImg, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        $mainRelPath = $dir . '/' . $baseName . '_main.' . $ext;
        $mainAbsPath = $disk->path($mainRelPath);

        $saved = match ($ext) {
            'jpg', 'jpeg' => imagejpeg($mainImg, $mainAbsPath, 85),
            'png'         => imagepng($mainImg, $mainAbsPath, 7),
            'webp'        => imagewebp($mainImg, $mainAbsPath, 80),
            'gif'         => imagegif($mainImg, $mainAbsPath),
            default       => false,
        };

        imagedestroy($mainImg);

        // Thumbnail 400x400
        $thumbW = min(400, $origW);
        $thumbH = min(400, $origH);
        $tRatio = min($thumbW / $origW, $thumbH / $origH);
        $tW     = (int) round($origW * $tRatio);
        $tH     = (int) round($origH * $tRatio);

        $thumbImg = imagecreatetruecolor($tW, $tH);
        imagecopyresampled($thumbImg, $src, 0, 0, 0, 0, $tW, $tH, $origW, $origH);

        $thumbRelPath = $dir . '/' . $baseName . '_thumb.webp';
        $thumbAbsPath = $disk->path($thumbRelPath);

        $thumbSaved = false;
        if (function_exists('imagewebp')) {
            $thumbSaved = imagewebp($thumbImg, $thumbAbsPath, 80);
        } else {
            // fallback to jpeg
            $thumbRelPath = $dir . '/' . $baseName . '_thumb.jpg';
            $thumbAbsPath = $disk->path($thumbRelPath);
            $thumbSaved   = imagejpeg($thumbImg, $thumbAbsPath, 80);
        }

        imagedestroy($thumbImg);
        imagedestroy($src);

        $mainExists    = $saved && file_exists($mainAbsPath);
        $optimizedSize = $mainExists ? filesize($mainAbsPath) : $fileRecord->original_size;

        // Only serve the optimized version if it's actually smaller than the original.
        if (! $mainExists || $optimizedSize >= $fileRecord->original_size) {
            if ($mainExists) @unlink($mainAbsPath);
            $fileRecord->update([
                'thumbnail_path'    => $thumbSaved ? $thumbRelPath : null,
                'processing_status' => 'skipped',
            ]);
            return;
        }

        $comprRatio = $fileRecord->original_size > 0
            ? round((1 - $optimizedSize / $fileRecord->original_size) * 100, 2)
            : 0.0;

        $fileRecord->update([
            'optimized_size'    => $optimizedSize,
            'compression_ratio' => $comprRatio,
            'thumbnail_path'    => $thumbSaved ? $thumbRelPath : null,
            // Optimized main image becomes the file served on preview/download.
            'preview_path'      => $mainRelPath,
            'processing_status' => 'optimized',
            'processed_at'      => now(),
        ]);
    }

    // ── PDF processing ────────────────────────────────────────────────────────

    private function processPdf(AmbassadorApplicationFile $fileRecord): void
    {
        // Check if Ghostscript is available. Probe common binary names since Windows
        // installs may expose gswin64c/gswin32c instead of a plain `gs` on PATH.
        // A Ghostscript version string looks like "10.03.1" or similar — validated with
        // a strict regex so a shell "command not found" error (which also contains a
        // period, e.g. "is not recognized as an internal or external command.") is never
        // mistaken for a valid version and doesn't send the job down the compression path.
        $gsBinary = null;
        foreach (['gs', 'gswin64c', 'gswin32c'] as $candidate) {
            $check = shell_exec(escapeshellarg($candidate) . ' --version 2>&1') ?? '';
            if (preg_match('/^\d+\.\d+(\.\d+)?\s*$/', trim($check))) {
                $gsBinary = $candidate;
                break;
            }
        }

        if (! $gsBinary) {
            $fileRecord->update(['processing_status' => 'skipped']);
            return;
        }

        $disk       = Storage::disk($fileRecord->disk);
        $sourcePath = $disk->path($fileRecord->path);
        $dir        = dirname($fileRecord->path);
        $baseName   = Str::uuid();
        $outputRel  = $dir . '/' . $baseName . '_compressed.pdf';
        $outputAbs  = $disk->path($outputRel);

        $cmd = sprintf(
            '%s -dBATCH -dNOPAUSE -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 '
            . '-dPDFSETTINGS=/ebook -sOutputFile=%s %s 2>&1',
            escapeshellarg($gsBinary),
            escapeshellarg($outputAbs),
            escapeshellarg($sourcePath)
        );

        shell_exec($cmd);

        // Validate output
        if (! file_exists($outputAbs) || filesize($outputAbs) === 0) {
            $this->markFailed($fileRecord, 'Ghostscript لم ينتج ملفاً صالحاً.');
            return;
        }

        $optimizedSize = filesize($outputAbs);

        // Only use compressed if it's smaller
        if ($optimizedSize >= $fileRecord->original_size) {
            @unlink($outputAbs);
            $fileRecord->update(['processing_status' => 'skipped']);
            return;
        }

        $ratio = round((1 - $optimizedSize / $fileRecord->original_size) * 100, 2);

        $fileRecord->update([
            'optimized_size'    => $optimizedSize,
            'compression_ratio' => $ratio,
            'preview_path'      => $outputRel,
            'processing_status' => 'optimized',
            'processed_at'      => now(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function markFailed(AmbassadorApplicationFile $fileRecord, string $error): void
    {
        $fileRecord->update([
            'processing_status' => 'failed',
            'processing_error'  => $error,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $fileRecord = AmbassadorApplicationFile::find($this->fileId);
        if ($fileRecord) {
            $this->markFailed($fileRecord, $exception->getMessage());
        }
    }
}
