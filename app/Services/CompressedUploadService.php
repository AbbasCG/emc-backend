<?php

namespace App\Services;

use App\Jobs\ProcessLargeUploadJob;
use App\Models\UploadRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

class CompressedUploadService
{
    /**
     * Per-context image optimization profiles.
     *
     * max_width / max_height — scaleDown bounds (aspect-ratio preserved, never upscaled)
     * quality                — initial WebP quality (0–100); falls back to 70 then 50 if over limit
     * max_output             — hard ceiling in bytes; upload is rejected if still exceeded at quality 50
     * thumb                  — null, or [width, height] for cover-cropped WebP thumbnail
     */
    const IMAGE_PROFILES = [
        'avatar' => [
            'max_width'  => 512,
            'max_height' => 512,
            'quality'    => 85,
            'max_output' => 150 * 1024,     // 150 KB
            'thumb'      => ['width' => 128, 'height' => 128],
        ],
        'course' => [
            'max_width'  => 1200,
            'max_height' => 675,
            'quality'    => 85,
            'max_output' => 500 * 1024,     // 500 KB
            'thumb'      => ['width' => 400, 'height' => 225],
        ],
        'banner' => [
            'max_width'  => 1920,
            'max_height' => 1080,
            'quality'    => 88,             // midpoint of 85–90 range
            'max_output' => 1024 * 1024,    // 1 MB
            'thumb'      => null,
        ],
    ];

    /**
     * Upload contexts accepted at POST /api/uploads/{context}.
     *
     * 'types'   — accepted file categories ('image' and/or 'pdf')
     * 'disk'    — 'public' (web-accessible) or 'local' (private, served via download route)
     * 'path'    — storage path prefix
     * 'profile' — IMAGE_PROFILES key for images; null for PDF-only contexts
     */
    const CONTEXTS = [
        'instructor'  => ['types' => ['image'],        'disk' => 'public', 'path' => 'uploads/instructors', 'profile' => 'avatar'],
        'student'     => ['types' => ['image'],        'disk' => 'public', 'path' => 'uploads/students',    'profile' => 'avatar'],
        'profile'     => ['types' => ['image'],        'disk' => 'public', 'path' => 'uploads/profiles',    'profile' => 'avatar'],
        'team'        => ['types' => ['image'],        'disk' => 'public', 'path' => 'uploads/team',        'profile' => 'avatar'],
        'course'      => ['types' => ['image'],        'disk' => 'public', 'path' => 'uploads/courses',     'profile' => 'course'],
        'workshop'    => ['types' => ['image'],        'disk' => 'public', 'path' => 'uploads/workshops',   'profile' => 'course'],
        'banner'      => ['types' => ['image'],        'disk' => 'public', 'path' => 'uploads/banners',     'profile' => 'banner'],
        'hero'        => ['types' => ['image'],        'disk' => 'public', 'path' => 'uploads/hero',        'profile' => 'banner'],
        'assignment'  => ['types' => ['image', 'pdf'], 'disk' => 'local',  'path' => 'uploads/assignments', 'profile' => 'course'],
        'certificate' => ['types' => ['pdf'],          'disk' => 'local',  'path' => 'uploads/certificates','profile' => null],
        'review'      => ['types' => ['image', 'pdf'], 'disk' => 'local',  'path' => 'uploads/reviews',     'profile' => 'course'],
    ];

    const IMAGE_MIMES   = ['image/jpeg', 'image/png', 'image/webp'];
    const PDF_MIME      = 'application/pdf';
    const IMAGE_MAX     = 5 * 1024 * 1024;   // 5 MB upload limit (enforced in UploadRequest)
    const PDF_MAX       = 15 * 1024 * 1024;  // 15 MB upload limit
    const QUEUE_IMG_MIN = 3 * 1024 * 1024;   // images ≥ 3 MB → queue
    const QUEUE_PDF_MIN = 8 * 1024 * 1024;   // PDFs   ≥ 8 MB → queue

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Main upload entry point. Validates, deduplicates, optimises, stores.
     *
     * Returns full metadata synchronously for small files, or a processing stub
     * (202-style) for large files dispatched to the queue.
     *
     * @throws ValidationException if the image still exceeds its profile limit after optimisation
     */
    public function upload(UploadedFile $file, string $context, int $userId): array
    {
        $mime = $file->getMimeType();
        $size = $file->getSize();
        $hash = hash_file('sha256', $file->getPathname());

        // Deduplication: same content already optimised → return existing record.
        $existing = UploadRecord::where('file_hash', $hash)->where('status', 'complete')->first();
        if ($existing) {
            return $this->format($existing);
        }

        $isImage   = in_array($mime, self::IMAGE_MIMES, true);
        $threshold = $isImage ? self::QUEUE_IMG_MIN : self::QUEUE_PDF_MIN;

        if ($size >= $threshold) {
            return $this->dispatchToQueue($file, $context, $userId, $hash, $mime, $size);
        }

        return $this->processSync(
            $file->getPathname(),
            $context,
            $userId,
            $hash,
            $mime,
            $size,
            $file->getClientOriginalName()
        );
    }

    /**
     * Process a previously-queued upload from its temp storage path.
     * Called exclusively by ProcessLargeUploadJob.
     */
    public function processQueued(int $recordId, string $tmpPath): void
    {
        $record = UploadRecord::findOrFail($recordId);
        $record->update(['status' => 'processing']);

        try {
            [$path, $optimizedSize, $thumbPath] = $this->compressAndStore(
                Storage::disk('local')->path($tmpPath),
                $record->mime_type,
                $record->context,
                $record->file_hash
            );

            $disk  = self::CONTEXTS[$record->context]['disk'];
            $saved = $this->savedPercent($record->original_size, $optimizedSize);
            $url   = $this->buildUrl($disk, $path, $recordId);
            [$thumbUrl] = $this->buildThumbUrl($disk, $thumbPath, $recordId);

            $record->update([
                'status'         => 'complete',
                'disk'           => $disk,
                'path'           => $path,
                'thumbnail_path' => $thumbPath,
                'optimized_size' => $optimizedSize,
                'url'            => $url,
                'thumbnail_url'  => $thumbUrl,
                'metadata'       => ['saved_percent' => $saved],
            ]);
        } catch (\Throwable $e) {
            $record->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        } finally {
            if (Storage::disk('local')->exists($tmpPath)) {
                Storage::disk('local')->delete($tmpPath);
            }
        }
    }

    /**
     * Serialise an UploadRecord to the standard metadata response shape.
     */
    public function format(UploadRecord $record): array
    {
        return [
            'record_id'     => $record->id,
            'status'        => $record->status,
            'original_size' => $record->original_size,
            'optimized_size'=> $record->optimized_size,
            'saved_percent' => $record->saved_percent,
            'url'           => $record->url,
            'thumbnail_url' => $record->thumbnail_url,
        ];
    }

    // ── Private: sync processing ──────────────────────────────────────────────

    private function processSync(
        string $sourcePath,
        string $context,
        int $userId,
        string $hash,
        string $mime,
        int $originalSize,
        string $originalName
    ): array {
        [$path, $optimizedSize, $thumbPath] = $this->compressAndStore($sourcePath, $mime, $context, $hash);

        $disk  = self::CONTEXTS[$context]['disk'];
        $saved = $this->savedPercent($originalSize, $optimizedSize);

        // Build URLs — for private disk we need the record id first.
        $publicUrl  = $disk === 'public' ? Storage::disk('public')->url($path) : null;
        $thumbPublic = ($thumbPath && $disk === 'public')
            ? Storage::disk('public')->url($thumbPath)
            : null;

        $record = UploadRecord::create([
            'user_id'           => $userId,
            'context'           => $context,
            'file_hash'         => $hash,
            'original_filename' => $originalName,
            'original_size'     => $originalSize,
            'optimized_size'    => $optimizedSize,
            'mime_type'         => $mime,
            'disk'              => $disk,
            'path'              => $path,
            'thumbnail_path'    => $thumbPath,
            'url'               => $publicUrl,
            'thumbnail_url'     => $thumbPublic,
            'status'            => 'complete',
            'metadata'          => ['saved_percent' => $saved],
        ]);

        // Private-disk URLs need the record id — patch after insert.
        if ($disk !== 'public') {
            $record->update([
                'url'           => route('api.uploads.download', $record->id),
                'thumbnail_url' => $thumbPath
                    ? route('api.uploads.download', $record->id) . '?thumb=1'
                    : null,
            ]);
        }

        return $this->format($record->fresh());
    }

    // ── Private: queue dispatch ───────────────────────────────────────────────

    private function dispatchToQueue(
        UploadedFile $file,
        string $context,
        int $userId,
        string $hash,
        string $mime,
        int $originalSize
    ): array {
        $ext     = strtolower($file->getClientOriginalExtension()) ?: 'bin';
        $tmpName = Str::uuid() . '.' . $ext;
        $tmpPath = 'upload_tmp/' . $tmpName;

        Storage::disk('local')->put($tmpPath, file_get_contents($file->getPathname()));

        $record = UploadRecord::create([
            'user_id'           => $userId,
            'context'           => $context,
            'file_hash'         => $hash,
            'original_filename' => $file->getClientOriginalName(),
            'original_size'     => $originalSize,
            'optimized_size'    => null,
            'mime_type'         => $mime,
            'disk'              => self::CONTEXTS[$context]['disk'],
            'path'              => null,
            'thumbnail_path'    => null,
            'url'               => null,
            'thumbnail_url'     => null,
            'status'            => 'pending',
            'job_id'            => $tmpName,
        ]);

        ProcessLargeUploadJob::dispatch($record->id, $tmpPath);

        return [
            'status'     => 'processing',
            'record_id'  => $record->id,
            'status_url' => route('api.uploads.status', $record->id),
            'message'    => 'Large file queued for processing.',
        ];
    }

    // ── Private: compression and storage ─────────────────────────────────────

    /**
     * Route to image compression or PDF storage.
     * Returns [storagePath, finalSizeBytes, thumbnailPath|null].
     */
    private function compressAndStore(
        string $sourcePath,
        string $mime,
        string $context,
        string $hash
    ): array {
        $config = self::CONTEXTS[$context];
        $prefix = $config['path'] . '/' . now()->format('Y/m');

        if (in_array($mime, self::IMAGE_MIMES, true)) {
            return $this->compressImage($sourcePath, $context, $config['disk'], $prefix, $hash);
        }

        return $this->storePdf($sourcePath, $config['disk'], $prefix, $hash);
    }

    /**
     * Compress a source image according to the context's profile.
     *
     * Always outputs WebP. Tries the profile quality, then 70, then 50.
     * Throws ValidationException if the image still exceeds max_output at quality 50.
     * Generates a cover-cropped thumbnail if the profile defines one.
     *
     * Returns [path, sizeBytes, thumbnailPath|null].
     */
    private function compressImage(
        string $sourcePath,
        string $context,
        string $disk,
        string $prefix,
        string $hash
    ): array {
        $profileKey = self::CONTEXTS[$context]['profile'] ?? 'course';
        $profile    = self::IMAGE_PROFILES[$profileKey];

        $manager = new ImageManager(new GdDriver());
        $img     = $manager->decodePath($sourcePath);

        // Scale down to profile dimensions, preserving aspect ratio.
        if ($img->width() > $profile['max_width'] || $img->height() > $profile['max_height']) {
            $img->scaleDown($profile['max_width'], $profile['max_height']);
        }

        // Encode as WebP, falling back to lower quality until within the output limit.
        $bytes = $this->encodeWebpWithFallback($img, $profile['quality'], $profile['max_output'], $context);

        $filename = substr($hash, 0, 12) . '_' . Str::random(8) . '.webp';
        $path     = $prefix . '/' . $filename;
        Storage::disk($disk)->put($path, $bytes);

        // Generate thumbnail from the original source for maximum quality.
        $thumbPath = null;
        if (! empty($profile['thumb'])) {
            $thumbPath = $this->generateThumbnail($sourcePath, $profile['thumb'], $disk, $prefix, $hash);
        }

        return [$path, strlen($bytes), $thumbPath];
    }

    /**
     * Encode an image as WebP, retrying at lower quality if over the max output limit.
     * Throws ValidationException if quality 50 still exceeds the limit.
     */
    private function encodeWebpWithFallback(
        ImageInterface $img,
        int $quality,
        int $maxOutput,
        string $context
    ): string {
        foreach ([$quality, 70, 50] as $q) {
            $candidate = (string) $img->encodeUsingMediaType('image/webp', quality: $q);
            if (strlen($candidate) <= $maxOutput) {
                return $candidate;
            }
        }

        $limitKb = (int) round($maxOutput / 1024);
        throw ValidationException::withMessages([
            'file' => [
                "Image cannot be optimized below {$limitKb} KB for '{$context}' uploads. "
                . 'Use a simpler image or reduce its dimensions before uploading.',
            ],
        ]);
    }

    /**
     * Generate a cover-cropped WebP thumbnail and store it under /thumbs/.
     */
    private function generateThumbnail(
        string $sourcePath,
        array $thumbConfig,
        string $disk,
        string $prefix,
        string $hash
    ): string {
        $manager = new ImageManager(new GdDriver());
        $img     = $manager->decodePath($sourcePath);

        $img->cover($thumbConfig['width'], $thumbConfig['height']);

        $bytes    = (string) $img->encodeUsingMediaType('image/webp', quality: 85);
        $filename = substr($hash, 0, 12) . '_thumb.webp';
        $path     = $prefix . '/thumbs/' . $filename;

        Storage::disk($disk)->put($path, $bytes);

        return $path;
    }

    /**
     * Store a PDF without modification. Returns [path, sizeBytes, null].
     */
    private function storePdf(
        string $sourcePath,
        string $disk,
        string $prefix,
        string $hash
    ): array {
        $contents = file_get_contents($sourcePath);
        $filename = substr($hash, 0, 12) . '_' . Str::random(8) . '.pdf';
        $path     = $prefix . '/' . $filename;

        Storage::disk($disk)->put($path, $contents);

        return [$path, strlen($contents), null];
    }

    // ── Private: URL helpers ──────────────────────────────────────────────────

    private function buildUrl(string $disk, string $path, int $recordId): string
    {
        return $disk === 'public'
            ? Storage::disk('public')->url($path)
            : route('api.uploads.download', $recordId);
    }

    /** Returns [thumbnailUrl|null]. */
    private function buildThumbUrl(string $disk, ?string $thumbPath, int $recordId): array
    {
        if (! $thumbPath) {
            return [null];
        }

        $url = $disk === 'public'
            ? Storage::disk('public')->url($thumbPath)
            : route('api.uploads.download', $recordId) . '?thumb=1';

        return [$url];
    }

    private function savedPercent(int $original, int $optimized): int
    {
        if ($original <= 0) {
            return 0;
        }

        return max(0, (int) round((1 - $optimized / $original) * 100));
    }
}
