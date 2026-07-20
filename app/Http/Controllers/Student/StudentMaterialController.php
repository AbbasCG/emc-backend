<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsCourseMaterialResource;
use App\Models\CourseMaterial;
use App\Services\LmsEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StudentMaterialController extends Controller
{
    public function index(Request $request, LmsEnrollmentService $enrollment): AnonymousResourceCollection
    {
        $user      = $request->user();
        $courseIds = $enrollment->enrolledCourseIds($user);
        $myClassGroupIds = $enrollment->classGroupIdsByCourse($user)->values();

        $query = CourseMaterial::query()
            ->with(['course:id,title'])
            ->where('is_visible', true)
            ->where(function ($q) use ($courseIds) {
                $q->whereIn('course_id', $courseIds)
                    ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            ->visibleToClassGroupIn($myClassGroupIds)
            // Never surface infected files
            ->where(function ($q) {
                $q->whereNull('virus_status')->orWhere('virus_status', '!=', 'infected');
            })
            ->orderBy('sort_order')
            ->orderByDesc('id');

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->query('course_id'));
        }
        if ($request->filled('module_id')) {
            $query->where('module_id', $request->query('module_id'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        $perPage = min((int) $request->query('per_page', 30), 100);

        return LmsCourseMaterialResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Materials retrieved successfully']);
    }

    public function show(Request $request, CourseMaterial $material): JsonResponse
    {
        $this->authorize('view', $material);

        $material->load(['course:id,title', 'uploader:id,name']);

        return (new LmsCourseMaterialResource($material))
            ->additional(['success' => true, 'message' => 'Material retrieved successfully'])
            ->response();
    }
}
