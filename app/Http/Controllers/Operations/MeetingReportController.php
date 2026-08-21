<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\MeetingReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تقارير ما بعد الاجتماع: محضر منظَّم (أنجزوا/سينجزون/يحتاجون ولماذا/قرارات)
 * مع تقييم لكل حاضر — مصمَّم ليكون سريعًا على المدير وسهل القياس لاحقًا.
 */
class MeetingReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reports = MeetingReport::query()
            ->with(['department:id,name', 'author:id,name'])
            ->withCount('ratings')
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->orderByDesc('meeting_date')
            ->paginate((int) ($request->per_page ?? 15));

        return response()->json(['success' => true, 'data' => $reports]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'department_id'          => 'required|exists:departments,id',
            'meeting_id'             => 'nullable|exists:meetings,id',
            'title'                  => 'required|string|max:255',
            'meeting_date'           => 'required|date',
            'achieved'               => 'nullable|string',
            'planned'                => 'nullable|string',
            'needs'                  => 'nullable|string',
            'needs_reason'           => 'nullable|string',
            'decisions'              => 'nullable|string',
            'notes'                  => 'nullable|string',
            'ratings'                => 'array',
            'ratings.*.user_id'      => 'required|exists:users,id',
            'ratings.*.attended'     => 'boolean',
            'ratings.*.rating'       => 'nullable|integer|min:1|max:5',
            'ratings.*.note'         => 'nullable|string|max:500',
        ]);

        $report = MeetingReport::create([
            ...collect($data)->except('ratings')->all(),
            'created_by' => $request->user()->id,
        ]);

        foreach ($data['ratings'] ?? [] as $row) {
            $report->ratings()->updateOrCreate(
                ['user_id' => $row['user_id']],
                [
                    'attended' => $row['attended'] ?? true,
                    'rating'   => $row['rating'] ?? null,
                    'note'     => $row['note'] ?? null,
                ],
            );
        }

        return response()->json([
            'success' => true,
            'data'    => $report->load(['department:id,name', 'ratings.user:id,name']),
        ], 201);
    }

    public function show(MeetingReport $meetingReport): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $meetingReport->load([
                'department:id,name',
                'author:id,name',
                'ratings.user:id,name',
            ]),
        ]);
    }
}
