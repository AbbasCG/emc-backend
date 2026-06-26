<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsCourseMaterialResource;
use App\Models\CourseMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminLmsMaterialController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CourseMaterial::query()->with(['course'])->orderBy('sort_order')->latest();

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->query('course_id'));
        }

        if ($request->filled('workshop_id')) {
            $query->where('workshop_id', $request->query('workshop_id'));
        }

        if ($request->filled('session_id')) {
            $query->where('session_id', $request->query('session_id'));
        }

        $perPage = min((int) $request->query('per_page', 30), 100);

        return LmsCourseMaterialResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Materials retrieved successfully']);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id'    => ['nullable', 'exists:courses,id'],
            'workshop_id'  => ['nullable', 'exists:workshops,id'],
            'session_id'   => ['nullable', 'exists:lms_sessions,id'],
            'title'        => ['required', 'string', 'max:255'],
            'description'  => ['nullable', 'string'],
            'file_path'    => ['nullable', 'string', 'max:2000'],
            'external_url' => ['nullable', 'string', 'max:2000'],
            'type'         => ['nullable', 'in:pdf,video,link,slide,document,other'],
            'sort_order'   => ['nullable', 'integer', 'min:0'],
        ]);

        $material = CourseMaterial::query()->create($validated);

        return (new LmsCourseMaterialResource($material))
            ->additional(['success' => true, 'message' => 'Material created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(CourseMaterial $material): JsonResponse
    {
        return (new LmsCourseMaterialResource($material))
            ->additional(['success' => true, 'message' => 'Material retrieved successfully'])
            ->response();
    }

    public function update(Request $request, CourseMaterial $material): JsonResponse
    {
        $validated = $request->validate([
            'course_id'    => ['sometimes', 'nullable', 'exists:courses,id'],
            'workshop_id'  => ['sometimes', 'nullable', 'exists:workshops,id'],
            'session_id'   => ['sometimes', 'nullable', 'exists:lms_sessions,id'],
            'title'        => ['sometimes', 'string', 'max:255'],
            'description'  => ['nullable', 'string'],
            'file_path'    => ['nullable', 'string', 'max:2000'],
            'external_url' => ['nullable', 'string', 'max:2000'],
            'type'         => ['nullable', 'in:pdf,video,link,slide,document,other'],
            'sort_order'   => ['nullable', 'integer', 'min:0'],
        ]);

        $material->update($validated);

        return (new LmsCourseMaterialResource($material->fresh()))
            ->additional(['success' => true, 'message' => 'Material updated successfully'])
            ->response();
    }

    public function destroy(CourseMaterial $material): JsonResponse
    {
        $material->delete();

        return response()->json(['success' => true, 'message' => 'Material deleted successfully']);
    }

    /**
     * Serve a material file inline (for preview). Auth + role enforced by route middleware.
     */
    public function file(Request $request, CourseMaterial $material): StreamedResponse|JsonResponse
    {
        if (! $material->file_path) {
            return response()->json(['success' => false, 'message' => 'لا يوجد ملف مرفق.'], 404);
        }

        [$disk, $path] = $this->resolveDisk($material->file_path);

        if ($disk === null) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود على الخادم.'], 404);
        }

        try {
            $mime     = Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream';
            $filename = basename($path);

            return Storage::disk($disk)->response($path, $filename, [
                'Content-Type'        => $mime,
                'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
                'Cache-Control'       => 'private, no-store',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'تعذّر تحميل الملف: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Serve a material file as a downloadable attachment. Auth + role enforced by route middleware.
     */
    public function download(Request $request, CourseMaterial $material): StreamedResponse|JsonResponse
    {
        if (! $material->file_path) {
            return response()->json(['success' => false, 'message' => 'هذه المادة لا تحتوي على ملف للتحميل.'], 404);
        }

        [$disk, $path] = $this->resolveDisk($material->file_path);

        if ($disk === null) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود على الخادم.'], 404);
        }

        $filename = basename($path);

        return Storage::disk($disk)->download($path, $filename, [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Resolve the storage disk for a given file path.
     * Probes 'local' (private) first, then 'public' (legacy), returns [null, path] when missing.
     *
     * @return array{0: string|null, 1: string}
     */
    private function resolveDisk(string $path): array
    {
        $candidates = array_values(array_unique(array_filter([
            $path,
            ltrim($path, '/'),
            preg_replace('#^(storage/|private/)#', '', $path) ?: null,
        ])));

        foreach ($candidates as $candidate) {
            if (Storage::disk('local')->exists($candidate)) {
                return ['local', $candidate];
            }

            if (Storage::disk('public')->exists($candidate)) {
                return ['public', $candidate];
            }
        }

        return [null, $path];
    }
}
