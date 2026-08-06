<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerProgram;
use App\Models\PartnerReport;
use Illuminate\Http\Request;

class AdminPartnerPortalController extends Controller
{
    public function portal(Partner $partner): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $partner->load(['partnerUsers.user', 'programs', 'reports', 'documents']),
        ]);
    }

    public function storeProgram(Request $request, Partner $partner): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'             => 'required|string|max:255',
            'description'       => 'nullable|string',
            'course_id'         => 'nullable|exists:courses,id',
            'track_id'          => 'nullable|exists:tracks,id',
            'status'            => 'in:active,completed,cancelled,draft',
            'start_date'        => 'nullable|date',
            'end_date'          => 'nullable|date',
            'participant_count' => 'integer|min:0',
        ]);

        $program = $partner->programs()->create($data);

        return response()->json(['success' => true, 'data' => $program], 201);
    }

    public function storeReport(Request $request, Partner $partner): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'   => 'required|string|max:255',
            'summary' => 'nullable|string',
            'data'    => 'nullable|array',
            'period'  => 'nullable|string|max:100',
            'status'  => 'in:draft,published',
        ]);

        $data['created_by'] = $request->user()->id;
        $report = $partner->reports()->create($data);

        return response()->json(['success' => true, 'data' => $report], 201);
    }
}
