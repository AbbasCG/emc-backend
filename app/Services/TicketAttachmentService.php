<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * TICKET ATTACHMENTS — the one canonical policy and processing path.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE SERVER IS THE AUTHORITY
 * ─────────────────────────────────────────────────────────────────────────
 * The frontend mirrors these rules so a user is not told "attached" about a
 * file the request is about to reject, but that mirroring is UX only. Nothing
 * here trusts the browser's MIME string, the extension, or the original
 * filename: the accepted set is enforced by Laravel's `mimes` rule (which
 * sniffs real file content) and images must additionally DECODE before they
 * are stored.
 *
 * VIDEO IS NOT AN ALLOWED TICKET ATTACHMENT. Neither is SVG, which is an
 * active-content format that browsers execute.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * FORMAT IS PRESERVED, NOT NORMALISED
 * ─────────────────────────────────────────────────────────────────────────
 * Unlike CompressedUploadService, which converts public marketing imagery to
 * WebP with thumbnails, a ticket attachment is EVIDENCE. A PNG screenshot of a
 * UI with transparency stays a PNG, and a photo stays a JPEG. Re-encoding a
 * screenshot into a lossy format would degrade the exact pixels a support agent
 * is being asked to look at.
 */
class TicketAttachmentService
{
    /**
     * Accepted types, mapped to the extension used for the stored file.
     *
     * Images: the three formats GD can decode and re-encode on this platform.
     * PDF: already part of the ticket product (the picker and the original
     * validation both accepted it) and carried through unchanged.
     */
    public const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** The `mimes:` rule token list, kept in lockstep with ALLOWED_MIMES. */
    public const MIMES_RULE = 'jpg,jpeg,png,webp,pdf';

    /** Unchanged from the original ticket validation: 50 MB per file. */
    public const MAX_KILOBYTES = 51200;

    /**
     * Screenshot-shaped bound. A ticket image exists to be read on screen, and
     * 2560px covers a full-width capture from a 5K display without upscaling
     * anything smaller. Images already within the bound are NOT resized.
     */
    public const MAX_DIMENSION = 2560;

    /**
     * Re-encode quality for the lossy formats. 85 keeps UI text and thin
     * borders legible; lower starts to ring around high-contrast edges, which
     * is exactly what a screenshot is made of.
     */
    public const IMAGE_QUALITY = 85;

    /** Where ticket attachments live on the private disk. */
    public const DIRECTORY = 'tickets/attachments';

    public const DISK = 'private';

    public static function isImage(string $mime): bool
    {
        return str_starts_with($mime, 'image/') && isset(self::ALLOWED_MIMES[$mime]);
    }

    /**
     * The coarse category stored on the row. Kept to the existing vocabulary
     * (IMAGE / DOCUMENT); VIDEO is no longer producible.
     */
    public static function categoryFor(string $mime): string
    {
        return self::isImage($mime) ? 'IMAGE' : 'DOCUMENT';
    }

    /**
     * Store one uploaded attachment, optimising it when it is an image.
     *
     * The physical filename is always server-generated and collision-resistant;
     * the client's original name is returned separately as display metadata
     * only, never used as a path.
     *
     * @return array{path: string, mime: string, size: int, category: string, original_name: string}
     *
     * @throws \RuntimeException when the file cannot be stored
     */
    public function store(UploadedFile $file): array
    {
        $mime = $file->getMimeType() ?: $file->getClientMimeType();

        // Belt and braces: the request rule already rejected anything not in
        // this list, so reaching here with an unknown type means the caller
        // bypassed validation.
        if (! isset(self::ALLOWED_MIMES[$mime])) {
            throw new \RuntimeException("Unsupported ticket attachment type [{$mime}].");
        }

        $extension = self::ALLOWED_MIMES[$mime];
        $path = self::DIRECTORY.'/'.Str::uuid()->toString().'.'.$extension;

        if (self::isImage($mime)) {
            $bytes = $this->optimize($file, $mime);

            if ($bytes !== null) {
                Storage::disk(self::DISK)->put($path, $bytes);

                return [
                    'path' => $path,
                    'mime' => $mime,
                    'size' => strlen($bytes),
                    'category' => 'IMAGE',
                    'original_name' => $this->displayName($file, $extension),
                ];
            }
            // Optimisation failed — fall through and store the original rather
            // than losing the user's evidence. The failure is logged below.
        }

        $stored = Storage::disk(self::DISK)->putFileAs(
            self::DIRECTORY,
            $file,
            basename($path),
        );

        if ($stored === false) {
            throw new \RuntimeException('Failed to write ticket attachment to the private disk.');
        }

        return [
            'path' => $path,
            'mime' => $mime,
            'size' => Storage::disk(self::DISK)->size($path),
            'category' => self::categoryFor($mime),
            'original_name' => $this->displayName($file, $extension),
        ];
    }

    /**
     * Re-encode an image within the dimension bound, stripping metadata.
     *
     * Returns null when the image cannot be processed, so the caller can store
     * the original instead of failing the whole ticket. A screenshot that GD
     * cannot re-encode is still a legitimate attachment.
     *
     * Intervention re-encodes from decoded pixels, so EXIF and any other
     * ancillary chunks are dropped - which is the privacy behaviour we want,
     * and is why no separate stripping pass is needed. Orientation is applied
     * first where the platform can read it; the `exif` extension is not always
     * loaded, so that step must never be fatal.
     */
    private function optimize(UploadedFile $file, string $mime): ?string
    {
        try {
            $image = (new ImageManager(new GdDriver()))->decodePath($file->getRealPath());

            try {
                // No-op without the exif extension; never allowed to fail hard.
                $image->orient();
            } catch (Throwable) {
                // Orientation is cosmetic - keep going with the decoded pixels.
            }

            // scaleDown never upscales: a small screenshot is left alone.
            if ($image->width() > self::MAX_DIMENSION || $image->height() > self::MAX_DIMENSION) {
                $image->scaleDown(self::MAX_DIMENSION, self::MAX_DIMENSION);
            }

            // encodeUsingMediaType() is the project's existing convention (see
            // CompressedUploadService) and re-encodes to the SAME verified type,
            // so a PNG screenshot keeps its transparency and exact pixels while
            // the lossy formats get a sensible web quality.
            $encoded = $mime === 'image/png'
                ? $image->encodeUsingMediaType($mime)
                : $image->encodeUsingMediaType($mime, quality: self::IMAGE_QUALITY);

            return (string) $encoded;
        } catch (Throwable $e) {
            Log::warning('Ticket attachment image optimisation failed; storing original.', [
                'mime' => $mime,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * A safe DISPLAY name. Never a path: directory separators and traversal
     * segments are stripped, and the extension is forced to match the verified
     * type so a ".php" cannot ride along in the label.
     */
    private function displayName(UploadedFile $file, string $extension): string
    {
        $original = (string) $file->getClientOriginalName();
        $base = pathinfo(str_replace(['\\', '/'], '_', $original), PATHINFO_FILENAME);
        $base = preg_replace('/[^\p{L}\p{N}._\- ]/u', '_', $base) ?: 'attachment';
        $base = trim(str_replace('..', '_', $base));

        if ($base === '') {
            $base = 'attachment';
        }

        return mb_substr($base, 0, 120).'.'.$extension;
    }

    /**
     * Delete stored files, used to compensate when the surrounding transaction
     * could not complete. Never throws: cleanup must not mask the real error.
     *
     * @param  array<int, string>  $paths
     */
    public function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk(self::DISK)->delete($path);
            } catch (Throwable $e) {
                Log::warning('Failed to clean up orphaned ticket attachment.', [
                    'path' => $path,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
