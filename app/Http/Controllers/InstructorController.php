<?php

namespace App\Http\Controllers;

use App\Http\Resources\InstructorResource;
use App\Models\Instructor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InstructorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->query('per_page', 15), 100);

        $instructors = Instructor::latest()->paginate($perPage);

        return InstructorResource::collection($instructors)
            ->additional([
                'success' => true,
                'message' => 'Instructors retrieved successfully',
            ]);
    }

    public function show(int $id): JsonResponse
    {
        $instructor = Instructor::with('courses')->findOrFail($id);

        return (new InstructorResource($instructor))
            ->additional([
                'success' => true,
                'message' => 'Instructor retrieved successfully',
            ])
            ->response();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'title' => 'nullable|string|max:255',
            'bio'   => 'nullable|string',
            'image' => 'nullable|string|max:255',
        ]);

        $instructor = Instructor::firstOrCreate(
            ['name' => $validated['name']],
            [
                'title' => $validated['title'] ?? null,
                'bio'   => $validated['bio']   ?? null,
                'image' => $validated['image'] ?? null,
            ]
        );

        $statusCode = $instructor->wasRecentlyCreated ? 201 : 200;
        $message    = $instructor->wasRecentlyCreated
            ? 'Instructor created successfully'
            : 'Instructor already exists';

        return (new InstructorResource($instructor))
            ->additional([
                'success' => true,
                'message' => $message,
            ])
            ->response()
            ->setStatusCode($statusCode);
    }
}
