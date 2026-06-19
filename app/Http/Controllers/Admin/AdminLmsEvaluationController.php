<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsEvaluationResource;
use App\Models\Evaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminLmsEvaluationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Evaluation::query()->latest();

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->query('course_id'));
        }

        if ($request->filled('workshop_id')) {
            $query->where('workshop_id', $request->query('workshop_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        $perPage = min((int) $request->query('per_page', 30), 100);

        return LmsEvaluationResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Evaluations retrieved successfully']);
    }
}
