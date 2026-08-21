<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConsultantApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** إدارة طلبات المستشارين: قائمة، تفاصيل، وتحديث الحالة مع ملاحظة المراجع. */
class ConsultantApplicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ConsultantApplication::query()
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, function ($q) use ($request) {
                $s = '%' . $request->search . '%';
                $q->where(fn ($w) => $w
                    ->where('full_name', 'like', $s)
                    ->orWhere('email', 'like', $s)
                    ->orWhere('specialty', 'like', $s));
            })
            ->latest();

        $counts = ConsultantApplication::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'success' => true,
            'data'    => $query->paginate((int) ($request->per_page ?? 20)),
            'meta'    => ['counts' => $counts],
        ]);
    }

    public function show(ConsultantApplication $consultantApplication): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $consultantApplication->load('reviewer:id,name'),
        ]);
    }

    public function updateStatus(Request $request, ConsultantApplication $consultantApplication): JsonResponse
    {
        $data = $request->validate([
            'status'        => 'required|in:' . implode(',', ConsultantApplication::STATUSES),
            'reviewer_note' => 'nullable|string|max:2000',
        ]);

        $consultantApplication->update([
            'status'        => $data['status'],
            'reviewer_note' => $data['reviewer_note'] ?? $consultantApplication->reviewer_note,
            'reviewed_by'   => $request->user()->id,
            'reviewed_at'   => now(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => $consultantApplication->fresh()->load('reviewer:id,name'),
        ]);
    }
}
