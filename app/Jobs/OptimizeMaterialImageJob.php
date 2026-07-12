<?php

namespace App\Jobs;

use App\Models\CourseMaterial;
use App\Models\MaterialAuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class OptimizeMaterialImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 3;
    public int $backoff = 30;

    public function __construct(private CourseMaterial $material) {}

    public function handle(): void
    {
        $material = $this->material->fresh();

        if (! $material || ! $material->file_path) {
            return;
        }

        $disk = Storage::disk($material->disk ?? 'private');

        if (! $disk->exists($material->file_path)) {
            Log::warning("OptimizeMaterialImageJob: file not found [{$material->file_path}]");
            return;
        }

        // Write original to a temp file so Intervention can read it
        $tempIn = tempnam(sys_get_temp_dir(), 'emc_img_');
        file_put_contents($tempIn, $disk->get($material->file_path));

        $uuid    = Str::uuid()->toString();
        $baseDir = dirname($material->file_path);
        $cfg     = config('materials.image');

        try {
            $thumbPath  = "{$baseDir}/{$uuid}_thumb.webp";
            $mediumPath = "{$baseDir}/{$uuid}_medium.webp";
            $origPath   = "{$baseDir}/{$uuid}_original.webp";

            $this->saveVariant($tempIn, $disk, $thumbPath,  $cfg['thumbnail_width'], $cfg['thumb_quality']);
            $this->saveVariant($tempIn, $disk, $mediumPath, $cfg['medium_width'],    $cfg['medium_quality']);
            $this->saveVariant($tempIn, $disk, $origPath,   $cfg['original_width'],  $cfg['orig_quality']);

            $material->update([
                'thumbnail_path'    => $thumbPath,
                'medium_path'       => $mediumPath,
                'image_optimized'   => true,
                'processing_status' => 'ready',
            ]);

            MaterialAuditLog::record($material, 'image_optimized', metadata: [
                'thumbnail' => $thumbPath,
                'medium'    => $mediumPath,
                'original'  => $origPath,
            ]);

        } catch (\Throwable $e) {
            $material->update(['processing_status' => 'failed']);
            Log::error("OptimizeMaterialImageJob failed for material #{$material->id}: " . $e->getMessage());
            throw $e;
        } finally {
            @unlink($tempIn);
        }
    }

    private function saveVariant(
        string $sourcePath,
        \Illuminate\Contracts\Filesystem\Filesystem $disk,
        string $storagePath,
        int $maxWidth,
        int $quality,
    ): void {
        $image = Image::read($sourcePath);

        if ($image->width() > $maxWidth) {
            $image->scaleDown(width: $maxWidth);
        }

        $webp   = $image->toWebp(quality: $quality);
        $tmpOut = tempnam(sys_get_temp_dir(), 'emc_webp_');

        try {
            file_put_contents($tmpOut, $webp);
            $disk->put($storagePath, fopen($tmpOut, 'rb'));
        } finally {
            @unlink($tmpOut);
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->material->fresh()?->update(['processing_status' => 'failed']);
        Log::error("OptimizeMaterialImageJob permanently failed for material #{$this->material->id}: " . $e->getMessage());
    }
}
