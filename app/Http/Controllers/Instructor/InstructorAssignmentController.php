<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsAssignmentResource;
use App\Models\Assignment;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InstructorAssignmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $profile = $user->instructorProfile;

        $courseIds = $profile
            ? Course::query()->where('instructor_id', $profile->id)->pluck('id')
            : collect();

        $query = Assignment::query()
            ->with(['workshop', 'submissions.user'])
            ->where(function ($q) use ($courseIds) {
                $q->whereIn('course_id', $courseIds)
                    ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            ->latest();

        $perPage = min((int) $request->query('per_page', 25), 100);

        return LmsAssignmentResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Assignments retrieved successfully']);
    }
}
