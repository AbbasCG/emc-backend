<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminLmsAttendanceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', \App\Models\LmsSession::class);

        $query = Attendance::query()->with(['session', 'user'])->latest();

        if ($request->filled('session_id')) {
            $query->where('session_id', $request->query('session_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->filled('course_id')) {
            $cid = $request->query('course_id');
            $query->whereHas('session', function ($q) use ($cid) {
                $q->where('course_id', $cid)
                    ->orWhereHas('workshop', fn ($w) => $w->where('course_id', $cid));
            });
        }

        $perPage = min((int) $request->query('per_page', 50), 200);

        return AttendanceResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Attendance records retrieved successfully']);
    }
}
