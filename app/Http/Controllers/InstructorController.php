<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\AdminInstructorController;
use App\Http\Resources\PublicInstructorResource;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InstructorController extends Controller
{
    private function publicQuery()
    {
        return Instructor::query()
            ->publicDirectory()
            ->with(['user:id,name,avatar,department,role,is_active'])
            ->withCount([
                'courses as courses_count' => fn ($q) => $q->where('status', 'published'),
                'learningPaths as learning_paths_count' => fn ($q) => $q->where('status', 'published'),
                'workshops',
            ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        User::whereIn('role', Instructor::PUBLIC_ROLES)
            ->where('is_active', true)
            ->whereDoesntHave('instructorProfile')
            ->select(['id', 'name'])
            ->each(fn (User $u) => AdminInstructorController::ensureProfile($u));

        $perPage = min((int) $request->query('per_page', 50), 100);

        $instructors = $this->publicQuery()
            ->orderBy('name')
            ->paginate($perPage);

        return PublicInstructorResource::collection($instructors)
            ->additional([
                'success' => true,
                'message' => 'Instructors retrieved successfully',
            ]);
    }

    public function show(string $identifier): JsonResponse
    {
        if ($this->isInvalidIdentifier($identifier)) {
            return $this->notFoundResponse();
        }

        $instructor = $this->resolvePublicInstructor($identifier, detailed: true);

        if (! $instructor) {
            return $this->notFoundResponse();
        }

        return (new PublicInstructorResource($instructor))
            ->additional([
                'success' => true,
                'message' => 'Instructor retrieved successfully',
            ])
            ->response();
    }

    public function showBySlug(string $slug): JsonResponse
    {
        return $this->show($slug);
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
                'bio'   => $validated['bio'] ?? null,
                'image' => $validated['image'] ?? null,
            ]
        );

        $statusCode = $instructor->wasRecentlyCreated ? 201 : 200;
        $message    = $instructor->wasRecentlyCreated
            ? 'Instructor created successfully'
            : 'Instructor already exists';

        return (new PublicInstructorResource($instructor))
            ->additional([
                'success' => true,
                'message' => $message,
            ])
            ->response()
            ->setStatusCode($statusCode);
    }

    private function resolvePublicInstructor(string $identifier, bool $detailed = false): ?Instructor
    {
        $query = $this->publicQuery();

        if (ctype_digit($identifier)) {
            $query->where('instructors.id', (int) $identifier);
        } else {
            $id = Instructor::idFromPublicSlug($identifier);
            if ($id === null) {
                return null;
            }
            $query->where('instructors.id', $id);
        }

        if ($detailed) {
            $query->with([
                'courses' => fn ($q) => $q
                    ->select(['id', 'instructor_id', 'slug', 'title'])
                    ->where('status', 'published')
                    ->orderBy('title'),
                'learningPaths' => fn ($q) => $q
                    ->select(['id', 'instructor_id', 'slug', 'title'])
                    ->where('status', 'published')
                    ->orderBy('title'),
            ]);
        }

        $instructor = $query->first();

        if (! $instructor) {
            return null;
        }

        if (! ctype_digit($identifier)) {
            $expected = Instructor::publicSlug($instructor->user?->name ?? $instructor->name, $instructor->id);
            if ($identifier !== $expected) {
                return null;
            }
        }

        return $instructor;
    }

    private function isInvalidIdentifier(string $identifier): bool
    {
        $trimmed = trim($identifier);

        if ($trimmed === '') {
            return true;
        }

        return in_array(strtolower($trimmed), ['undefined', 'null', 'nan'], true);
    }

    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Instructor not found.',
        ], 404);
    }
}
