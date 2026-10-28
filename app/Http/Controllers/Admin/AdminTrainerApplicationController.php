<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TrainerApplication;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTrainerApplicationController extends Controller
{
    public const STATUSES = ['new', 'under_review', 'approved', 'rejected'];

    private const TRANSITIONS = [
        'new'          => ['under_review', 'approved', 'rejected'],
        'under_review' => ['approved', 'rejected'],
        'approved'     => [],
        'rejected'     => [],
    ];

    private const LIST_COLUMNS = [
        'id', 'uuid', 'status', 'full_name', 'email', 'phone_or_whatsapp',
        'primary_specialty', 'years_of_experience', 'delivery_mode',
        'hourly_rate', 'currency', 'currency_other', 'created_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search'   => 'nullable|string|max:100',
            'status'   => 'nullable|string|in:' . implode(',', self::STATUSES),
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $applications = TrainerApplication::query()
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(trim((string) ($data['search'] ?? '')) !== '', function ($q) use ($data) {
                $term = '%' . trim($data['search']) . '%';
                $q->where(fn ($w) => $w->where('full_name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone_or_whatsapp', 'like', $term)
                    ->orWhere('primary_specialty', 'like', $term));
            })
            ->latest()
            ->paginate((int) ($data['per_page'] ?? 15), self::LIST_COLUMNS);

        return response()->json($applications);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(TrainerApplication::findOrFail($id));
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:' . implode(',', self::STATUSES),
        ]);

        $application = TrainerApplication::findOrFail($id);
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

        $userId = $request->user()?->id;

        $application->status = $to;
        $application->reviewed_by = $userId;
        $application->reviewed_at = now();
        $application->save();

        if (class_exists(AuditLogService::class)) {
            try {
                AuditLogService::log(
                    action: 'trainer_application_status_updated',
                    modelType: TrainerApplication::class,
                    modelId: $application->id,
                    oldValues: ['status' => $from],
                    newValues: ['status' => $to, 'reviewed_by' => $userId, 'reviewed_at' => $application->reviewed_at->toIso8601String()],
                );
            } catch (\Throwable) {}
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة طلب المدرب بنجاح.',
            'application' => $application,
        ]);
    }

    public function updateNotes(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'admin_notes' => 'nullable|string|max:5000',
        ]);

        $application = TrainerApplication::findOrFail($id);
        $application->admin_notes = $data['admin_notes'] ?? null;
        $application->save();

        return response()->json([
            'success' => true,
            'message' => 'تم حفظ ملاحظات الإدارة بنجاح.',
            'application' => $application,
        ]);
    }
}
