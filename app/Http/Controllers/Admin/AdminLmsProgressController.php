<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentProgressResource;
use App\Models\StudentProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminLmsProgressController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StudentProgress::class);

        $query = StudentProgress::query()->with(['course', 'user'])->latest();

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->query('course_id'));
        }

        if ($request->filled('track_id')) {
            $query->where('track_id', $request->query('track_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min((int) $request->query('per_page', 30), 100);

        return StudentProgressResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Student progress retrieved successfully']);
    }
}
