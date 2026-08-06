<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Workshop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstructorWorkshopController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $profile = $user->instructorProfile;

        if (! $profile) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'meta'    => ['total' => 0, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        $query = Workshop::with(['course:id,title,slug,start_date,status'])
            ->where('instructor_id', $profile->id);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->query('course_id'));
        }

        $workshops = $query->orderBy('start_date')->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $workshops->map(fn ($w) => [
                'id'               => $w->id,
                'title'            => $w->title,
                'slug'             => $w->slug,
                'description'      => $w->description,
                'status'           => $w->status,
                'start_date'       => $w->start_date,
                'start_time'       => $w->start_time,
                'end_date'         => $w->end_date,
                'end_time'         => $w->end_time,
                'location_type'    => $w->location_type,
                'meeting_link'     => $w->meeting_link,
                'registration_open'=> $w->registration_open,
                'course'           => $w->course ? [
                    'id'         => $w->course->id,
                    'title'      => $w->course->title,
                    'slug'       => $w->course->slug,
                    'start_date' => $w->course->start_date,
                    'status'     => $w->course->status,
                ] : null,
                'created_at'       => $w->created_at,
            ]),
            'meta' => [
                'total'        => $workshops->total(),
                'current_page' => $workshops->currentPage(),
                'last_page'    => $workshops->lastPage(),
                'per_page'     => $workshops->perPage(),
            ],
        ]);
    }
}
