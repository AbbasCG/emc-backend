<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsEvaluationResource;
use App\Models\Evaluation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentEvaluationController extends Controller
{
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
