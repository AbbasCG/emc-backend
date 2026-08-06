<?php

namespace App\Http\Controllers;

use App\Http\Resources\TrackResource;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TrackController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->query('per_page', 15), 100);

        $tracks = Track::query()
            ->where('status', 'active')
            ->withCount('courses')
            ->latest()
            ->paginate($perPage);

        return TrackResource::collection($tracks)
            ->additional([
                'success' => true,
                'message' => 'Tracks retrieved successfully',
            ]);
    }

    public function show(string $slug): JsonResponse
    {
        $track = Track::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->with(['courses' => fn ($q) => $q->where('status', 'active')->with(['instructor', 'features'])])
            ->firstOrFail();

        return (new TrackResource($track))
            ->additional([
                'success' => true,
                'message' => 'Track retrieved successfully',
            ])
            ->response();
    }
}
