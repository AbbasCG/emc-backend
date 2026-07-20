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
     * GET /api/materials/{material}/preview
     *
     * Authorized inline preview for PDF materials — same policy guard and
     * disk resolution as download(), only the Content-Disposition differs
     * (inline vs attachment). Non-PDF materials fall back to 415, since a
     * generic "preview" of an arbitrary binary is not meaningful/safe.
     */
    public function preview(Request $request, CourseMaterial $material): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $material);

        if (! $material->file_path) {
            return response()->json(['success' => false, 'message' => 'هذه المادة لا تحتوي على ملف للمعاينة.'], 404);
        }

        if ($material->mime_type !== 'application/pdf') {
            return response()->json(['success' => false, 'message' => 'المعاينة المباشرة متاحة لملفات PDF فقط.'], 415);
        }

        $disk = Storage::disk($material->disk ?? 'private');

        if (! $disk->exists($material->file_path)) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود على الخادم.'], 404);
        }

        MaterialAuditLog::record($material, 'previewed', $request->user()?->id, $request->ip(), $request->userAgent());

        $downloadName = $material->original_filename ?? basename($material->file_path);
        $safeAscii    = preg_replace('/[\r\n"\\\\]/', '_', $downloadName);
        $encodedUtf8  = rawurlencode($downloadName);

        return $disk->response(
            $material->file_path,
            null,
            [
                'Content-Type'           => 'application/pdf',
                'Cache-Control'          => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition'    => "inline; filename=\"{$safeAscii}\"; filename*=UTF-8''{$encodedUtf8}",
            ],
        );
    }

    /**
     * GET /api/materials/{material}/stream
     *
     * Authorized HTTP Range-aware video streaming. Same policy guard/disk
     * resolution as download()/preview(). Local video storage is genuinely
     * used by this platform (mp4/mov/avi are accepted upload MIME types,
     * stored on the private local-filesystem disk) — this is not
     * speculative infrastructure. Only the `local` filesystem driver is
     * supported (confirmed via config/filesystems.php — the `private` disk
     * uses `driver => local`), so `Storage::path()` yields a real
     * filesystem path safe to `fopen()` directly; a non-local disk would
     * fall back to a full (non-range) streamed response instead.
     */
    public function stream(Request $request, CourseMaterial $material): StreamedResponse|JsonResponse|\Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('view', $material);

        if (! $material->file_path) {
            return response()->json(['success' => false, 'message' => 'هذه المادة لا تحتوي على ملف فيديو.'], 404);
        }

        if (! str_starts_with((string) $material->mime_type, 'video/')) {
            return response()->json(['success' => false, 'message' => 'البث المباشر متاح لملفات الفيديو فقط.'], 415);
        }

        $disk = Storage::disk($material->disk ?? 'private');

        if (! $disk->exists($material->file_path)) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود على الخادم.'], 404);
        }

        MaterialAuditLog::record($material, 'streamed', $request->user()?->id, $request->ip(), $request->userAgent());

        // Only the local driver exposes a real filesystem path for range reads.
        $diskConfig = config('filesystems.disks.' . ($material->disk ?? 'private') . '.driver');
        if ($diskConfig !== 'local') {
            return $disk->response($material->file_path, null, ['Content-Type' => $material->mime_type]);
        }

        $path = $disk->path($material->file_path);
        $size = filesize($path);
        $rangeHeader = $request->header('Range');

        $start = 0;
        $end = $size - 1;

        if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
            $start = $m[1] === '' ? 0 : (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : (int) $m[2];

            if ($start > $end || $start >= $size || $end >= $size) {
                return response('', 416, [
                    'Content-Range' => "bytes */{$size}",
                ]);
            }
        }

        $length = $end - $start + 1;
        $status = $rangeHeader ? 206 : 200;

        $headers = [
            'Content-Type'   => $material->mime_type,
            'Accept-Ranges'  => 'bytes',
            'Content-Length' => (string) $length,
            'Cache-Control'  => 'private, no-store',
        ];
        if ($rangeHeader) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return response()->stream(function () use ($path, $start, $length) {
            $chunkSize = 8192;
            $handle = fopen($path, 'rb');
            fseek($handle, $start);
            $remaining = $length;
            while ($remaining > 0 && ! feof($handle)) {
                $read = (int) min($chunkSize, $remaining);
                echo fread($handle, $read);
                flush();
                $remaining -= $read;
            }
            fclose($handle);
        }, $status, $headers);
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
