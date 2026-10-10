<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExpertApplication;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Expert / trainer applications — reviewed by the admin tier and the AI
 * department manager (route group in routes/api.php).
 *
 * STATUS WORKFLOW — the vocabulary the table was created with (migration
 * default 'new') and the dashboard renders:
 *
 *   new ──▶ under_review ──▶ approved
 *    │                  └──▶ rejected
 *    └──▶ approved / rejected (decided without a separate review step)
 *
 * approved and rejected are final. The previous validator accepted a second,
 * unused vocabulary (pending/reviewed/accepted) that nothing wrote or read;
 * it is gone, so there is exactly one status system.
 */
class AdminExpertApplicationController extends Controller
{
    public const STATUSES = ['new', 'under_review', 'approved', 'rejected'];

    /** Allowed next states per current state. Final states allow none. */
    private const TRANSITIONS = [
        'new'          => ['under_review', 'approved', 'rejected'],
        'under_review' => ['approved', 'rejected'],
        'approved'     => [],
        'rejected'     => [],
    ];

    /** Columns shown in the list — the full record is returned by show(). */
    private const LIST_COLUMNS = [
        'id', 'uuid', 'status', 'full_name', 'email', 'whatsapp_number',
        'country', 'city', 'primary_specialty', 'created_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search'   => 'nullable|string|max:100',
            'status'   => 'nullable|string|in:'.implode(',', self::STATUSES),
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $applications = ExpertApplication::query()
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(trim((string) ($data['search'] ?? '')) !== '', function ($q) use ($data) {
                $term = '%'.trim($data['search']).'%';
                $q->where(fn ($w) => $w->where('full_name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('primary_specialty', 'like', $term));
            })
            ->latest()
            ->paginate((int) ($data['per_page'] ?? 15), self::LIST_COLUMNS);

        return response()->json($applications);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(ExpertApplication::findOrFail($id));
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:'.implode(',', self::STATUSES),
        ]);

        $application = ExpertApplication::findOrFail($id);
        $from = (string) $application->status;
        $to = $data['status'];

        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن نقل الطلب من هذه الحالة إلى الحالة المطلوبة.',
                'code'    => 'INVALID_STATUS_TRANSITION',
                'allowed' => self::TRANSITIONS[$from] ?? [],
            ], 422);
        }

        // The table already carries reviewer columns; every decision records
        // who made it and when (in addition to the audit log entry below).
        $application->status = $to;
        $application->reviewed_by = $request->user()->id;
        $application->reviewed_at = now();
        $application->save();

        AuditLogService::log(
            'EXPERT_APPLICATION_STATUS_CHANGED',
            'ExpertApplication',
            $application->id,
            $application->full_name,
            ['status' => $from],
            ['status' => $to],
        );

        return response()->json(['message' => 'تم تحديث حالة الطلب بنجاح', 'application' => $application]);
    }
}
