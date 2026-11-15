<?php

namespace App\Services\AssignmentUploads;

use Illuminate\Http\UploadedFile;

/** One uploaded file that passed every check. Immutable: what is stored is exactly what was verified. */
final class InspectedUpload
{
    public function __construct(
        public readonly UploadedFile $file,
        /** Sanitised original name, for DISPLAY only. Never used to build a path. */
        public readonly string $displayName,
        /** Lower-case extension the file was verified as (jpeg is kept as jpeg). */
        public readonly string $extension,
        /** Server-decided MIME type, never the browser's. */
        public readonly string $mime,
        public readonly int $size,
        public readonly string $sha256,
        public readonly string $group,
    ) {
    }
}
