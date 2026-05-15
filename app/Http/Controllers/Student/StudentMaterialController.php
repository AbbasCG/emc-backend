<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsCourseMaterialResource;
use App\Models\CourseMaterial;
use App\Services\LmsEnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StudentMaterialController extends Controller
{
    public function index(Request $request, LmsEnrollmentService $enrollment): AnonymousResourceCollection
    {
        $user = $request->user();
        $courseIds = $enrollment->enrolledCourseIds($user);

        $query = CourseMaterial::query()
            ->where(function ($q) use ($courseIds) {
                $q->whereIn('course_id', $courseIds)
                    ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            ->orderBy('sort_order')
            ->orderByDesc('id');

        $perPage = min((int) $request->query('per_page', 30), 100);

        return LmsCourseMaterialResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Materials retrieved successfully']);
    }
}
