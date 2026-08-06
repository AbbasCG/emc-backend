<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AmbassadorApplicationFileResource;
use App\Models\AmbassadorApplication;
use App\Models\AmbassadorApplicationFile;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAmbassadorApplicationFileController extends Controller
{
    private const ALLOWED_ROLES = ['super_admin', 'tech_admin', 'admin', 'hr_manager'];

    // ── GET /admin/ambassador-applications/{ambassadorApplication}/files ──────

    public function index(Request $request, AmbassadorApplication $ambassadorApplication): JsonResponse
    {
        if (! $this->authorized($request)) {
            return $this->forbidden();
        }

        $files = $ambassadorApplication->files()->get();

        return response()->json([
            'success' => true,
            'data'    => AmbassadorApplicationFileResource::collection($files),
        ]);
    }

    // ── GET /admin/ambassador-applications/{ambassadorApplication}/files/{file}/preview ─

    public function preview(Request $request, AmbassadorApplication $ambassadorApplication, int $fileId): Response|JsonResponse
    {
        if (! $this->authorized($request)) {
            return $this->forbidden();
        }

        $file = $this->resolveFile($ambassadorApplication, $fileId);
        if (! $file) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود.'], 404);
        }

        if (! $this->isPreviewable($file)) {
            return response()->json(['success' => false, 'message' => 'لا يمكن معاينة هذا النوع من الملفات.'], 422);
        }

        $disk = Storage::disk($file->disk);
        $servePath = $this->resolveServePath($file, $disk);
        if (! $servePath) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود على القرص.'], 404);
        }

        Log::info('Ambassador file previewed', [
            'file_id'        => $file->id,
            'application_id' => $ambassadorApplication->id,
            'admin_id'       => $request->user()->id,
        ]);

        $safeName = preg_replace('/[^a-zA-Z0-9._\- ]/', '_', $file->original_name) ?: $file->stored_name;

        return response(
            $disk->get($servePath),
            200,
            [
                'Content-Type'        => $file->mime_type,
                'Content-Disposition' => 'inline; filename="' . $safeName . '"',
            ]
        );
    }

    // ── GET /admin/ambassador-applications/{ambassadorApplication}/files/{file}/download ─

    public function download(Request $request, AmbassadorApplication $ambassadorApplication, int $fileId): StreamedResponse|JsonResponse
    {
        if (! $this->authorized($request)) {
            return $this->forbidden();
        }

        $file = $this->resolveFile($ambassadorApplication, $fileId);
        if (! $file) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود.'], 404);
        }

        $disk = Storage::disk($file->disk);
        $servePath = $this->resolveServePath($file, $disk);
        if (! $servePath) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود على القرص.'], 404);
        }

        Log::info('Ambassador file downloaded', [
            'file_id'        => $file->id,
            'application_id' => $ambassadorApplication->id,
            'admin_id'       => $request->user()->id,
        ]);

        AuditLogService::log(
            'ambassador_file.downloaded',
            'AmbassadorApplicationFile',
            $file->id,
            $file->original_name,
            [],
            ['application_id' => $ambassadorApplication->id],
        );

        // Sanitize filename
        $safeName = preg_replace('/[^a-zA-Z0-9._\- ]/', '_', $file->original_name) ?: $file->stored_name;

        return $disk->download($servePath, $safeName);
    }

    // ── GET /admin/ambassador-applications/{ambassadorApplication}/files/{file}/thumbnail ─

    public function thumbnail(Request $request, AmbassadorApplication $ambassadorApplication, int $fileId): Response|JsonResponse|\Illuminate\Http\RedirectResponse
    {
        if (! $this->authorized($request)) {
            return $this->forbidden();
        }

        $file = $this->resolveFile($ambassadorApplication, $fileId);
        if (! $file) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود.'], 404);
        }

        $disk = Storage::disk($file->disk);

        // Return thumbnail if it exists
        if ($file->thumbnail_path && $disk->exists($file->thumbnail_path)) {
            return response(
                $disk->get($file->thumbnail_path),
                200,
                ['Content-Type' => 'image/webp']
            );
        }

        // Fallback: redirect to preview
        return redirect()->route('admin.ambassador-applications.files.preview', [$ambassadorApplication->id, $fileId]);
    }

    // ── DELETE /admin/ambassador-applications/{ambassadorApplication}/files/{file} ───

    public function destroy(Request $request, AmbassadorApplication $ambassadorApplication, int $fileId): JsonResponse
    {
        if (! $this->authorized($request)) {
            return $this->forbidden();
        }

        $file = $this->resolveFile($ambassadorApplication, $fileId);
        if (! $file) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود.'], 404);
        }

        $disk = Storage::disk($file->disk);

        // Delete from storage
        if ($disk->exists($file->path)) {
            $disk->delete($file->path);
        }
        if ($file->thumbnail_path && $disk->exists($file->thumbnail_path)) {
            $disk->delete($file->thumbnail_path);
        }
        if ($file->preview_path && $disk->exists($file->preview_path)) {
            $disk->delete($file->preview_path);
        }

        $file->delete(); // soft delete

        Log::info('Ambassador file deleted', [
            'file_id'        => $file->id,
            'application_id' => $ambassadorApplication->id,
            'admin_id'       => $request->user()->id,
        ]);

        AuditLogService::log(
            'ambassador_file.deleted',
            'AmbassadorApplicationFile',
            $file->id,
            $file->original_name,
            ['path' => $file->path],
            [],
        );

        return response()->json(['success' => true, 'message' => 'تم حذف الملف.']);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function authorized(Request $request): bool
    {
        return in_array($request->user()?->role, self::ALLOWED_ROLES, true);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'غير مصرح.'], 403);
    }

    /**
     * Prefer the optimized/compressed variant (preview_path) when it exists on disk;
     * fall back to the original file. Never fails silently onto a missing path.
     */
    private function resolveServePath(AmbassadorApplicationFile $file, $disk): ?string
    {
        if ($file->preview_path && $disk->exists($file->preview_path)) {
            return $file->preview_path;
        }

        return $disk->exists($file->path) ? $file->path : null;
    }

    private function resolveFile(AmbassadorApplication $ambassadorApplication, int $fileId): ?AmbassadorApplicationFile
    {
        /** @var AmbassadorApplicationFile|null $file */
        $file = AmbassadorApplicationFile::find($fileId);

        // IDOR check: file must belong to this application
        if (! $file || $file->ambassador_application_id !== $ambassadorApplication->id) {
            return null;
        }

        return $file;
    }

    private function isPreviewable(AmbassadorApplicationFile $file): bool
    {
        return str_starts_with($file->mime_type, 'image/')
            || $file->mime_type === 'application/pdf';
    }
}
