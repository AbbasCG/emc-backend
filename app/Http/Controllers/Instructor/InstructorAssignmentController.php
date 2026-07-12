<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsAssignmentResource;
use App\Models\Course;
use App\Models\CourseAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InstructorAssignmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user    = $request->user();
        $profile = $user->instructorProfile;

        $courseIds = $profile
            ? Course::query()->where('instructor_id', $profile->id)->pluck('id')
            : collect();

        $query = CourseAssignment::query()
            ->with(['course:id,title'])
            ->withCount([
                'submissions as submissions_count',
                'submissions as pending_submissions_count' => fn ($q) => $q
                    ->whereNotNull('submitted_at')
                    ->whereNotIn('status', ['reviewed', 'graded', 'needs_revision']),
                'submissions as graded_submissions_count' => fn ($q) => $q
                    ->whereIn('status', ['reviewed', 'graded']),
            ])
            ->whereIn('course_id', $courseIds)
            ->latest();

        $perPage = min((int) $request->query('per_page', 25), 100);

        return LmsAssignmentResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Assignments retrieved successfully']);
    }
}
