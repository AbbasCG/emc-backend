<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsSessionResource;
use App\Models\Course;
use App\Models\LmsSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InstructorSessionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $profile = $user->instructorProfile;
        $courseIds = $profile
            ? Course::query()->where('instructor_id', $profile->id)->pluck('id')
            : collect();

        $query = LmsSession::query()->with(['workshop', 'instructor']);

        if ($profile) {
            $query->where(function ($q) use ($profile, $courseIds) {
                $q->where('instructor_id', $profile->id)
                    ->orWhereIn('course_id', $courseIds)
                    ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            });
        } else {
            $query->whereRaw('1 = 0');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $query->orderByDesc('session_date')->orderByDesc('start_time');

        $perPage = min((int) $request->query('per_page', 25), 100);

        return LmsSessionResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Sessions retrieved successfully']);
    }
}
