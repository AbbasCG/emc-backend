<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsEvaluationResource;
use App\Models\Evaluation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentEvaluationController extends Controller
{
    /**
     * GET /api/student/reviews
     *
     * Returns all evaluations submitted by the authenticated student.
     * Scoped strictly to the current user — students cannot see other students' reviews.
     */
    public function index(Request $request): JsonResponse
    {
        $evaluations = Evaluation::query()
            ->where('user_id', $request->user()->id)
            ->select(['id', 'course_id', 'workshop_id', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $evaluations,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Evaluation::class);

        $validated = $request->validate([
            'course_id' => ['nullable', 'exists:courses,id'],
            'workshop_id' => ['nullable', 'exists:workshops,id'],
            'instructor_id' => ['nullable', 'exists:instructors,id'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'content_quality' => ['nullable', 'integer', 'min:1', 'max:5'],
            'instructor_quality' => ['nullable', 'integer', 'min:1', 'max:5'],
            'organization_quality' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ]);

        $evaluation = Evaluation::query()->create(array_merge(
            $validated,
            ['user_id' => $request->user()->id]
        ));

        return (new LmsEvaluationResource($evaluation))
            ->additional(['success' => true, 'message' => 'Evaluation submitted successfully.'])
            ->response()
            ->setStatusCode(201);
    }
}
