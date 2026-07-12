<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\CourseMaterial;
use App\Models\MaterialAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaterialDownloadController extends Controller
{
    /**
     * GET /api/materials/{material}/download
     *
     * Policy-guarded secure download. Never exposes storage paths.
     */
    public function download(Request $request, CourseMaterial $material): StreamedResponse|JsonResponse
    {
        $this->authorize('download', $material);

        if (! $material->file_path) {
            return response()->json(['success' => false, 'message' => 'هذه المادة لا تحتوي على ملف للتحميل.'], 404);
        }

        $disk = Storage::disk($material->disk ?? 'private');

        if (! $disk->exists($material->file_path)) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود على الخادم.'], 404);
        }

        // ── Audit ─────────────────────────────────────────────────────────────
        MaterialAuditLog::record(
            $material,
            'downloaded',
            $request->user()?->id,
            $request->ip(),
            $request->userAgent(),
        );

        // ── Stats ─────────────────────────────────────────────────────────────
        DB::table('course_materials')
            ->where('id', $material->id)
            ->update([
                'download_count'      => DB::raw('download_count + 1'),
                'last_downloaded_at'  => now(),
            ]);

        $downloadName = $material->original_filename ?? basename($material->file_path);

        // Ensure ZIP files always carry a .zip extension regardless of stored name.
        $zipMimes = ['application/zip', 'application/x-zip-compressed', 'application/x-zip'];
        if (in_array($material->mime_type, $zipMimes, true) || $material->extension === 'zip') {
            if (!str_ends_with(strtolower($downloadName), '.zip')) {
                $downloadName = rtrim($downloadName, '.') . '.zip';
            }
        }

        // Sanitize: strip CR/LF to prevent header injection.
        $safeAscii  = preg_replace('/[\r\n"\\\\]/', '_', $downloadName);
        // RFC 5987 percent-encode for filename* parameter.
        $encodedUtf8 = rawurlencode($downloadName);

        return $disk->download(
            $material->file_path,
            $downloadName,
            [
                'Content-Type'           => $material->mime_type ?? 'application/octet-stream',
                'Cache-Control'          => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                // filename* (RFC 5987) for Unicode filenames; ASCII filename= as fallback.
                'Content-Disposition'    => "attachment; filename=\"{$safeAscii}\"; filename*=UTF-8''{$encodedUtf8}",
            ],
        );
    }

    /**
     * GET /api/materials/{material}/thumbnail
     *
     * Serve the WebP thumbnail for image materials (inline).
     */
    public function thumbnail(Request $request, CourseMaterial $material): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $material);

        $path = $material->thumbnail_path ?? $material->file_path;

        if (! $path) {
            return response()->json(['success' => false, 'message' => 'لا توجد صورة مصغّرة.'], 404);
        }

        $disk = Storage::disk($material->disk ?? 'private');

        if (! $disk->exists($path)) {
            return response()->json(['success' => false, 'message' => 'الصورة المصغّرة غير موجودة.'], 404);
        }

        return $disk->response($path, null, [
            'Content-Type'  => 'image/webp',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * GET /api/materials/{material}/medium
     *
     * Serve the medium-resolution WebP version inline.
     */
    public function medium(Request $request, CourseMaterial $material): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $material);

        $path = $material->medium_path ?? $material->file_path;

        if (! $path) {
            return response()->json(['success' => false, 'message' => 'لا توجد نسخة متوسطة.'], 404);
        }

        $disk = Storage::disk($material->disk ?? 'private');

        if (! $disk->exists($path)) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود.'], 404);
        }

        return $disk->response($path, null, [
            'Content-Type'  => 'image/webp',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
