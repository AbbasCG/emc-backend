<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentProgressResource;
use App\Models\StudentProgress;
use App\Services\StudentProgressService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StudentProgressController extends Controller
{
    public function index(Request $request, StudentProgressService $progress): AnonymousResourceCollection
    {
        $user = $request->user();
        $progress->syncForUser($user);

        $rows = StudentProgress::query()
            ->with('course.track')
            ->where('user_id', $user->id)
            ->latest()
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return StudentProgressResource::collection($rows)
            ->additional(['success' => true, 'message' => 'Progress retrieved successfully']);
    }
}
