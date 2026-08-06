<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaterialRequest;
use App\Http\Resources\LmsCourseMaterialResource;
use App\Models\CourseMaterial;
use App\Models\MaterialAuditLog;
use App\Services\LearningContentEligibilityService;
use App\Services\Material\MaterialUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminLmsMaterialController extends Controller
{
    public function __construct(private MaterialUploadService $uploader) {}

    // ── List ──────────────────────────────────────────────────────────────────

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CourseMaterial::query()
            ->with(['course', 'uploader'])
            ->orderBy('sort_order')
            ->latest();

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->query('course_id'));
        }
        if ($request->filled('module_id')) {
            $query->where('module_id', $request->query('module_id'));
        }
        if ($request->filled('session_id')) {
            $query->where('session_id', $request->query('session_id'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        $perPage = min((int) $request->query('per_page', 30), 100);

        return LmsCourseMaterialResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Materials retrieved successfully']);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function store(StoreMaterialRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user      = $request->user();

        if ($request->hasFile('file')) {
            $material = $this->uploader->store(
                $request->file('file'),
                collect($validated)->except('file')->toArray(),
                $user,
            );
        } else {
            // URL-only material
            $material = CourseMaterial::create(array_merge(
                collect($validated)->except('file')->toArray(),
                ['uploaded_by' => $user->id],
            ));

            MaterialAuditLog::record($material, 'uploaded', $user->id, metadata: [
                'external_url' => $validated['external_url'] ?? null,
            ]);
        }

        return (new LmsCourseMaterialResource($material->load('course')))
            ->additional(['success' => true, 'message' => 'تمت إضافة المادة بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(CourseMaterial $material): JsonResponse
    {
        $material->load(['course', 'uploader']);

        return (new LmsCourseMaterialResource($material))
            ->additional(['success' => true, 'message' => 'Material retrieved successfully'])
            ->response();
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, CourseMaterial $material): JsonResponse
    {
        $validated = $request->validate([
            'title'            => ['sometimes', 'string', 'max:255'],
            'description'      => ['nullable', 'string', 'max:5000'],
            'type'             => ['sometimes', 'string'],
            'sort_order'       => ['nullable', 'integer', 'min:0'],
            'is_visible'       => ['nullable', 'boolean'],
            'external_url'     => ['nullable', 'url', 'max:2000'],
            'course_id'        => ['sometimes', 'nullable', 'exists:courses,id'],
            'class_group_id'   => ['sometimes', 'nullable', 'exists:class_groups,id'],
            'module_id'        => ['sometimes', 'nullable', 'exists:course_modules,id'],
            'lesson_id'        => ['sometimes', 'nullable', 'exists:lessons,id'],
            'session_id'       => ['sometimes', 'nullable', 'exists:lms_sessions,id'],
            'course_session_id'=> ['sometimes', 'nullable', 'exists:course_sessions,id'],
        ]);

        $scopeErrors = app(LearningContentEligibilityService::class)->materialScopeErrors([
            'course_id'         => $validated['course_id'] ?? $material->course_id,
            'class_group_id'    => $validated['class_group_id'] ?? $material->class_group_id,
            'module_id'         => array_key_exists('module_id', $validated) ? $validated['module_id'] : $material->module_id,
            'lesson_id'         => array_key_exists('lesson_id', $validated) ? $validated['lesson_id'] : $material->lesson_id,
            'session_id'        => array_key_exists('session_id', $validated) ? $validated['session_id'] : $material->session_id,
            'course_session_id' => array_key_exists('course_session_id', $validated) ? $validated['course_session_id'] : $material->course_session_id,
        ]);
        if ($scopeErrors !== []) {
            throw ValidationException::withMessages($scopeErrors);
        }

        $user = $request->user();

        // File replacement
        if ($request->hasFile('file')) {
            $newMaterial = $this->uploader->replace($request->file('file'), $material, $user);

            return (new LmsCourseMaterialResource($newMaterial->load('course')))
                ->additional(['success' => true, 'message' => 'تم تحديث الملف بنجاح'])
                ->response();
        }

        $material->update($validated);

        MaterialAuditLog::record($material, 'updated', $user->id, metadata: $validated);

        return (new LmsCourseMaterialResource($material->fresh()->load('course')))
            ->additional(['success' => true, 'message' => 'تم تحديث المادة بنجاح'])
            ->response();
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function destroy(Request $request, CourseMaterial $material): JsonResponse
    {
        $user = $request->user();

        MaterialAuditLog::record($material, 'deleted', $user->id, metadata: [
            'original_filename' => $material->original_filename,
            'size'              => $material->size,
        ]);

        // Remove physical files
        $this->uploader->deleteFiles($material);

        $material->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف المادة بنجاح']);
    }

    // ── Secure file delivery ──────────────────────────────────────────────────

    public function download(Request $request, CourseMaterial $material): StreamedResponse|JsonResponse
    {
        if (! $material->file_path) {
            return response()->json(['success' => false, 'message' => 'لا يوجد ملف مرفق.'], 404);
        }

        $disk = Storage::disk($material->disk ?? 'private');
        if (! $disk->exists($material->file_path)) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود.'], 404);
        }

        MaterialAuditLog::record($material, 'downloaded', $request->user()?->id,
            $request->ip(), $request->userAgent());

        DB::table('course_materials')
            ->where('id', $material->id)
            ->update(['download_count' => DB::raw('download_count + 1'), 'last_downloaded_at' => now()]);

        return $disk->download(
            $material->file_path,
            $material->original_filename ?? basename($material->file_path),
            ['Content-Type' => $material->mime_type ?? 'application/octet-stream', 'Cache-Control' => 'private, no-store'],
        );
    }

    public function file(Request $request, CourseMaterial $material): StreamedResponse|JsonResponse
    {
        if (! $material->file_path) {
            return response()->json(['success' => false, 'message' => 'لا يوجد ملف.'], 404);
        }

        $disk = Storage::disk($material->disk ?? 'private');
        if (! $disk->exists($material->file_path)) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود.'], 404);
        }

        $mime = $material->mime_type ?? $disk->mimeType($material->file_path) ?? 'application/octet-stream';

        MaterialAuditLog::record($material, 'viewed', $request->user()?->id, $request->ip(), $request->userAgent());

        return $disk->response($material->file_path, $material->original_filename ?? basename($material->file_path), [
            'Content-Type'           => $mime,
            'Content-Disposition'    => 'inline; filename="' . addslashes($material->original_filename ?? '') . '"',
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ── Admin storage stats ───────────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        $total = CourseMaterial::whereNotNull('file_path')->count();

        $byType = CourseMaterial::whereNotNull('file_path')
            ->select('type', DB::raw('COUNT(*) as count'), DB::raw('SUM(size) as total_size'))
            ->groupBy('type')
            ->get();

        $totalBytes = CourseMaterial::whereNotNull('file_path')->sum('size');

        $largest = CourseMaterial::whereNotNull('file_path')
            ->orderByDesc('size')
            ->limit(10)
            ->get(['id', 'title', 'original_filename', 'type', 'size', 'download_count', 'created_at']);

        $mostDownloaded = CourseMaterial::whereNotNull('file_path')
            ->where('download_count', '>', 0)
            ->orderByDesc('download_count')
            ->limit(10)
            ->get(['id', 'title', 'original_filename', 'type', 'size', 'download_count']);

        $unused = CourseMaterial::whereNotNull('file_path')
            ->where('download_count', 0)
            ->whereNull('deleted_at')
            ->count();

        $duplicates = CourseMaterial::whereNotNull('sha256')
            ->select('sha256', DB::raw('COUNT(*) as cnt'))
            ->groupBy('sha256')
            ->having('cnt', '>', 1)
            ->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_files'      => $total,
                'total_size_bytes' => $totalBytes,
                'total_size_human' => $this->humanSize($totalBytes),
                'by_type'          => $byType,
                'largest_files'    => LmsCourseMaterialResource::collection($largest),
                'most_downloaded'  => LmsCourseMaterialResource::collection($mostDownloaded),
                'unused_count'     => $unused,
                'duplicate_count'  => $duplicates,
            ],
        ]);
    }

    private function humanSize(?int $bytes): string
    {
        if (! $bytes) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = 0;
        while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
