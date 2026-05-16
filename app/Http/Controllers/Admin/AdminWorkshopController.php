<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workshop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminWorkshopController extends Controller
{
    // GET /api/admin/workshops
    public function index(Request $request): JsonResponse
    {
        $workshops = Workshop::with(['course:id,title,slug'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->course_id, fn ($q) => $q->where('course_id', $request->course_id))
            ->when($request->search, fn ($q) => $q->where('title', 'like', "%{$request->search}%"))
            ->orderBy('sort_order')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json([
            'success' => true,
            'data'    => $workshops->map(fn ($w) => $this->format($w)),
            'meta'    => [
                'total'        => $workshops->total(),
                'current_page' => $workshops->currentPage(),
                'last_page'    => $workshops->lastPage(),
            ],
        ]);
    }

    // POST /api/admin/workshops
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'course_id'   => 'required|exists:courses,id',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'nullable|in:active,draft,archived',
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date|after_or_equal:start_date',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $data['slug']   = $this->uniqueSlug($data['title']);
        $data['status'] = $data['status'] ?? 'draft';

        $workshop = Workshop::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Workshop created successfully',
            'data'    => $this->format($workshop->load('course:id,title,slug')),
        ], 201);
    }

    // GET /api/admin/workshops/{workshop}
    public function show(Workshop $workshop): JsonResponse
    {
        $workshop->load(['course:id,title,slug', 'sessions', 'materials', 'assignments']);

        return response()->json(['success' => true, 'data' => $this->format($workshop)]);
    }

    // PUT /api/admin/workshops/{workshop}
    public function update(Request $request, Workshop $workshop): JsonResponse
    {
        $data = $request->validate([
            'course_id'   => 'sometimes|exists:courses,id',
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'sometimes|in:active,draft,archived',
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date|after_or_equal:start_date',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        if (isset($data['title'])) {
            $data['slug'] = $this->uniqueSlug($data['title'], $workshop->id);
        }

        $workshop->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Workshop updated successfully',
            'data'    => $this->format($workshop->fresh()->load('course:id,title,slug')),
        ]);
    }

    // DELETE /api/admin/workshops/{workshop}
    public function destroy(Workshop $workshop): JsonResponse
    {
        $workshop->delete();

        return response()->json(['success' => true, 'message' => 'Workshop deleted successfully']);
    }

    private function format(Workshop $w): array
    {
        return [
            'id'          => $w->id,
            'title'       => $w->title,
            'slug'        => $w->slug,
            'description' => $w->description,
            'status'      => $w->status,
            'start_date'  => $w->start_date,
            'end_date'    => $w->end_date,
            'sort_order'  => $w->sort_order,
            'course'      => $w->course ? ['id' => $w->course->id, 'title' => $w->course->title] : null,
            'created_at'  => $w->created_at,
        ];
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base  = Str::slug($title);
        $slug  = $base;
        $count = 1;

        while (
            Workshop::query()
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base . '-' . $count++;
        }

        return $slug;
    }
}
