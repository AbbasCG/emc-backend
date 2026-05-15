<?php

namespace App\Http\Controllers;

use App\Http\Resources\TrackResource;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class AdminTrackController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $query = Track::query()->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('q')) {
            $q = $request->query('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('title', 'like', '%' . $q . '%')
                    ->orWhere('slug', 'like', '%' . $q . '%');
            });
        }

        return TrackResource::collection($query->paginate($perPage))
            ->additional([
                'success' => true,
                'message' => 'Tracks retrieved successfully',
            ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:tracks,slug'],
            'description' => ['nullable', 'string'],
            'outcome_title' => ['nullable', 'string', 'max:255'],
            'duration' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,draft,archived'],
            'image' => ['nullable', 'string', 'max:500'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_free' => ['boolean'],
        ]);

        $validated['slug'] = $validated['slug'] ?? $this->uniqueSlug($validated['title']);
        $validated['status'] = $validated['status'] ?? 'active';
        $validated['is_free'] = $validated['is_free'] ?? true;

        $track = Track::create($validated);

        return (new TrackResource($track))
            ->additional([
                'success' => true,
                'message' => 'Track created successfully',
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Track $track): JsonResponse
    {
        $track->loadCount('courses');

        return (new TrackResource($track))
            ->additional([
                'success' => true,
                'message' => 'Track retrieved successfully',
            ])
            ->response();
    }

    public function update(Request $request, Track $track): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'unique:tracks,slug,' . $track->id],
            'description' => ['nullable', 'string'],
            'outcome_title' => ['nullable', 'string', 'max:255'],
            'duration' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,draft,archived'],
            'image' => ['nullable', 'string', 'max:500'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_free' => ['boolean'],
        ]);

        if (array_key_exists('title', $validated) && ! array_key_exists('slug', $validated)) {
            $validated['slug'] = $this->uniqueSlug($validated['title'], $track->id);
        }

        $track->update($validated);

        return (new TrackResource($track->fresh()))
            ->additional([
                'success' => true,
                'message' => 'Track updated successfully',
            ])
            ->response();
    }

    public function destroy(Track $track): JsonResponse
    {
        $track->delete();

        return response()->json([
            'success' => true,
            'message' => 'Track deleted successfully',
        ]);
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $count = 1;

        while (
            Track::query()
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base . '-' . $count++;
        }

        return $slug;
    }
}
