<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InstructorCourseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $profile = $user->instructorProfile;

        $query = Course::query()->with(['track', 'instructor', 'features']);

        if ($profile) {
            $query->where('instructor_id', $profile->id);
        } else {
            $query->whereRaw('1 = 0');
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        return CourseResource::collection($query->latest()->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Courses retrieved successfully']);
    }
}
