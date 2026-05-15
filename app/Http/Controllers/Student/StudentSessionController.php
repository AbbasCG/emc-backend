<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsSessionResource;
use App\Models\LmsSession;
use App\Services\LmsEnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StudentSessionController extends Controller
{
    public function index(Request $request, LmsEnrollmentService $enrollment): AnonymousResourceCollection
    {
        $user = $request->user();
        $courseIds = $enrollment->enrolledCourseIds($user);

        $query = LmsSession::query()
            ->with(['workshop', 'instructor'])
            ->where(function ($q) use ($courseIds) {
                $q->whereIn('course_id', $courseIds)
                    ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            ->orderByDesc('session_date')
            ->orderByDesc('start_time');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        return LmsSessionResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Sessions retrieved successfully']);
    }
}
