<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Models\VolunteerPointActivity;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerPointRedemption;
use App\Models\VolunteerPointRequest;
use App\Services\AuditLogService;
use App\Services\ImpactPointsRecipients;
use App\Support\ImpactLevels;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VolunteerPointRequestController extends Controller
{
    public function __construct(private readonly ImpactPointsRecipients $recipients)
    {
    }

    /** فحص هل المستخدم يملك صلاحية اعتماد الموارد البشرية */
    private function canApprove(User $actor): bool
    {
        return $actor->hasPermission(Permissions::MEMBERS_MANAGE)
            || $this->recipients->scopeLabel($actor) === ImpactPointsRecipients::SCOPE_ORGANIZATION
            || in_array($actor->role, ['super_admin', 'admin', 'hr_manager', 'general_manager']);
    }

    /** جلب قائمة التصنيفات والأنشطة المعتمدة */
    public function activities(): JsonResponse
    {
        $activities = VolunteerPointActivity::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $categories = $activities->groupBy('category_key')->map(function ($group) {
            $first = $group->first();
            return [
                'key'         => $first->category_key,
                'name_ar'     => $first->category_name_ar,
                'activities'  => $group->map(fn ($act) => [
                    'id'                  => $act->id,
                    'name_ar'             => $act->name_ar,
                    'type'                => $act->type,
                    'points_min'          => $act->points_min,
                    'points_max'          => $act->points_max,
                    'description_ar'      => $act->description_ar,
                    'requires_attachment' => $act->requires_attachment,
                    'monthly_cap'         => $act->monthly_cap,
                ])->values()->all(),
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'data'    => [
                'categories' => $categories,
                'activities' => $activities,
            ],
        ]);
    }

    /** تقديم طلب منح نقاط جديد — يذهب للموارد البشرية بحالة بانتظار الاعتماد */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $this->recipients->canAward($actor)) {
            return response()->json(['success' => false, 'message' => 'ليست لديك صلاحية رفع طلبات نقاط الأثر.'], 403);
        }

        $validated = $request->validate([
            'user_id'       => 'required|exists:users,id',
            'department_id' => 'nullable|exists:departments,id',
            'activity_id'   => 'required|exists:volunteer_point_activities,id',
            'points'        => 'required|integer',
            'reason'        => 'required|string|min:6|max:1000',
            'attachment'    => 'nullable|file|max:10240|mimes:jpeg,png,jpg,pdf,doc,docx,webp',
        ], [
            'reason.min' => 'يجب كتابة سبب واضح ومفصل بحد أدنى 6 أحرف.',
            'attachment.max' => 'حجم المرفق يجب ألا يتجاوز 10 ميجابايت.',
        ]);

        $recipientId = (int) $validated['user_id'];

        if ($recipientId === $actor->id) {
            return response()->json(['success' => false, 'message' => 'لا يمكنك تقديم طلب منح نقاط لنفسك.', 'code' => 'SELF_AWARD'], 422);
        }

        if (! $this->recipients->isAwardableBy($actor, $recipientId)) {
            return response()->json(['success' => false, 'message' => 'هذا المستخدم ليس عضواً فعالاً في فريق EMC ضمن نطاق صلاحيتك.', 'code' => 'RECIPIENT_NOT_ELIGIBLE'], 422);
        }

        /** @var VolunteerPointActivity $activity */
        $activity = VolunteerPointActivity::findOrFail($validated['activity_id']);

        $points = (int) $validated['points'];

        // التحقق من نطاق النقاط بدقة
        if ($activity->type === 'fixed') {
            if ($points !== $activity->points_min) {
                return response()->json([
                    'success' => false,
                    'message' => "قيمة النقاط لهذا النشاط محددة بـ {$activity->points_min} نقطة فقط.",
                    'code'    => 'FIXED_POINTS_MISMATCH',
                ], 422);
            }
        } else {
            if ($points < $activity->points_min || $points > $activity->points_max) {
                return response()->json([
                    'success' => false,
                    'message' => "النقاط يجب أن تكون بين {$activity->points_min} و {$activity->points_max} نقطة لهذا النشاط.",
                    'code'    => 'POINTS_OUT_OF_RANGE',
                ], 422);
            }
        }

        // إذا كان النشاط يتطلب مرفقاً إلزامياً
        if ($activity->requires_attachment && ! $request->hasFile('attachment')) {
            return response()->json([
                'success' => false,
                'message' => 'هذا النشاط يتطلب إرفاق ملف أو صورة إثبات وفق سياسة EMC.',
                'code'    => 'ATTACHMENT_REQUIRED',
            ], 422);
        }

        // فحص السقف الشهري للأعمال الداخلية (2500 نقطة) — وتُستثنى المبادرات والمعسكرات والجوائز
        $isExempt = VolunteerPointsController::isExemptCategory($activity->category_key);
        if (! $isExempt) {
            $usedMonth = $this->recipients->monthCappedPoints($recipientId);
            if ($usedMonth + $points > VolunteerPointsController::MONTHLY_CAP) {
                $left = max(0, VolunteerPointsController::MONTHLY_CAP - $usedMonth);
                return response()->json([
                    'success' => false,
                    'message' => "يتجاوز السقف الشهري للأعمال الداخلية (".VolunteerPointsController::MONTHLY_CAP." نقطة) — المتبقي لهذا العضو هذا الشهر {$left} نقطة.",
                    'code'    => 'MONTHLY_CAP_EXCEEDED',
                ], 422);
            }
        }

        // فحص السقف الخاص بالنشاط إن وجد
        if ($activity->monthly_cap) {
            $activityMonthPoints = VolunteerPointAward::where('user_id', $recipientId)
                ->where('category', $activity->category_key)
                ->whereMonth('awarded_at', now()->month)
                ->whereYear('awarded_at', now()->year)
                ->sum('points');

            if ($activityMonthPoints + $points > $activity->monthly_cap) {
                return response()->json([
                    'success' => false,
                    'message' => "يتجاوز الحد الأقصى الشهري لهذا النشاط ({$activity->monthly_cap} نقطة).",
                    'code'    => 'ACTIVITY_MONTHLY_CAP_EXCEEDED',
                ], 422);
            }
        }

        // استنتاج الإدارة
        $departmentId = $validated['department_id'] ?? null;
        if (! $departmentId) {
            $memberProfile = $this->recipients->describe($recipientId);
            $departmentId = $memberProfile['department_id'] ?? null;
        }

        // حفظ المرفق إن وجد
        $attachmentPath = null;
        $attachmentName = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentName = $file->getClientOriginalName();
            $attachmentPath = $file->store('volunteer_points_attachments', 'public');
        }

        $pointRequest = VolunteerPointRequest::create([
            'user_id'          => $recipientId,
            'department_id'    => $departmentId,
            'requester_id'     => $actor->id,
            'activity_id'      => $activity->id,
            'category_key'     => $activity->category_key,
            'activity_name'    => $activity->name_ar,
            'points_requested' => $points,
            'reason'           => trim($validated['reason']),
            'attachment_path'  => $attachmentPath,
            'attachment_name'  => $attachmentName,
            'status'           => VolunteerPointRequest::STATUS_PENDING,
        ]);

        AuditLogService::log(
            action: 'VOLUNTEER_POINTS_REQUESTED',
            entityType: 'VolunteerPointRequest',
            entityId: $pointRequest->id,
            entityName: "طلب نقاط {$activity->name_ar}",
            extra: [
                'user_id'  => $recipientId,
                'activity' => $activity->name_ar,
                'points'   => $points,
            ],
            description: "طلب منح نقاط أثر جديد للمتطوع #{$recipientId} بانتظار اعتماد الموارد البشرية."
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال طلب النقاط للاعتماد بنجاح، ولن تُضاف إلى رصيد المتطوع إلا بعد مراجعة واعتماد الموارد البشرية.',
            'data'    => $pointRequest->load(['user:id,name', 'department:id,name', 'requester:id,name', 'activity']),
        ], 201);
    }

    /** قائمة طلبات النقاط مع الفلترة والبحث */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $isHR = $this->canApprove($actor);

        $query = VolunteerPointRequest::query()
            ->with([
                'user:id,name,role',
                'department:id,name',
                'requester:id,name',
                'reviewer:id,name',
                'activity',
            ]);

        // إذا لم يكن HR، يرى طلبات إدارته أو الطلبات التي أنشأها بنفسه فقط
        if (! $isHR) {
            $ledDeptIds = $this->recipients->departmentScope($actor);
            if ($ledDeptIds && $ledDeptIds->isNotEmpty()) {
                $query->where(function ($q) use ($actor, $ledDeptIds) {
                    $q->whereIn('department_id', $ledDeptIds)
                      ->orWhere('requester_id', $actor->id);
                });
            } else {
                $query->where('requester_id', $actor->id);
            }
        }

        // الفلاتر
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->department_id);
        }
        if ($request->filled('category_key')) {
            $query->where('category_key', $request->category_key);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->user_id);
        }
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('requester', fn ($rq) => $rq->where('name', 'like', "%{$search}%"))
                  ->orWhere('activity_name', 'like', "%{$search}%")
                  ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min(100, (int) ($request->per_page ?? 20)));
        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        // إحصائيات سريعة للطلبات
        $stats = [
            'pending'  => (clone $query)->where('status', VolunteerPointRequest::STATUS_PENDING)->count(),
            'approved' => (clone $query)->where('status', VolunteerPointRequest::STATUS_APPROVED)->count(),
            'rejected' => (clone $query)->where('status', VolunteerPointRequest::STATUS_REJECTED)->count(),
        ];

        return response()->json([
            'success' => true,
            'data'    => $paginator->items(),
            'meta'    => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'stats'        => $stats,
            ],
            'capabilities' => [
                'can_approve' => $isHR,
            ],
        ]);
    }

    /** عرض تفاصيل طلب نقطة مع ملخص رصيد المتطوع ونقاطه في الشهر الحالي */
    public function show(Request $request, VolunteerPointRequest $pointRequest): JsonResponse
    {
        $actor = $request->user();
        $isHR = $this->canApprove($actor);

        if (! $isHR && $pointRequest->requester_id !== $actor->id) {
            $ledDeptIds = $this->recipients->departmentScope($actor);
            if (! $ledDeptIds || ! $ledDeptIds->contains($pointRequest->department_id)) {
                return response()->json(['success' => false, 'message' => 'غير مصرح لك بعرض هذا الطلب.'], 403);
            }
        }

        $user = $pointRequest->user;
        $lifetime = (int) VolunteerPointAward::where('user_id', $user->id)->sum('points');
        $redeemed = (int) VolunteerPointRedemption::where('user_id', $user->id)->sum('points');
        $monthCapped = $this->recipients->monthCappedPoints($user->id);
        $monthTotal = $this->recipients->monthTotalPoints($user->id);
        $months = (int) $user->created_at->diffInMonths(now());
        $level = ImpactLevels::eligible($lifetime, $months);
        $isExempt = VolunteerPointsController::isExemptCategory($pointRequest->category_key);

        return response()->json([
            'success' => true,
            'data'    => $pointRequest->load(['user:id,name,role', 'department:id,name', 'requester:id,name', 'reviewer:id,name', 'activity']),
            'volunteer_summary' => [
                'lifetime_points'       => $lifetime,
                'available_points'      => max(0, $lifetime - $redeemed),
                'month_points'          => $monthCapped,
                'month_total_points'    => $monthTotal,
                'level'                 => $level,
                'monthly_cap_left'      => max(0, VolunteerPointsController::MONTHLY_CAP - $monthCapped),
                'is_request_exempt'     => $isExempt,
            ],
            'attachment_url' => $pointRequest->attachment_path ? Storage::disk('public')->url($pointRequest->attachment_path) : null,
        ]);
    }

    /** اعتماد طلب النقاط — تضاف النقاط إلى الرصيد وتُسجل في الدفتر */
    public function approve(Request $request, VolunteerPointRequest $pointRequest): JsonResponse
    {
        $actor = $request->user();

        if (! $this->canApprove($actor)) {
            return response()->json(['success' => false, 'message' => 'اعتماد طلبات النقاط متاح لإدارة الموارد البشرية فقط.'], 403);
        }

        if ($pointRequest->status === VolunteerPointRequest::STATUS_APPROVED) {
            return response()->json(['success' => false, 'message' => 'تم اعتماد هذا الطلب مسبقاً.'], 422);
        }

        $validated = $request->validate([
            'approved_points' => 'nullable|integer|min:1|max:5000',
            'review_notes'    => 'nullable|string|max:1000',
        ]);

        $finalPoints = $validated['approved_points'] ?? $pointRequest->points_requested;
        $notes = $validated['review_notes'] ?? null;

        // التحقق من السقف الشهري للأعمال الداخلية فقط
        $isExempt = VolunteerPointsController::isExemptCategory($pointRequest->category_key);
        if (! $isExempt) {
            $usedMonth = $this->recipients->monthCappedPoints($pointRequest->user_id);
            if ($usedMonth + $finalPoints > VolunteerPointsController::MONTHLY_CAP) {
                $left = max(0, VolunteerPointsController::MONTHLY_CAP - $usedMonth);
                return response()->json([
                    'success' => false,
                    'message' => "يتجاوز السقف الشهري للأعمال الداخلية للمتطوع (".VolunteerPointsController::MONTHLY_CAP." نقطة) — المتبقي له {$left} نقطة.",
                    'code'    => 'MONTHLY_CAP_EXCEEDED',
                ], 422);
            }
        }

        $award = DB::transaction(function () use ($pointRequest, $actor, $finalPoints, $notes) {
            // 1. إنشاء صف الدفتر الحسابي التراكمي
            $award = VolunteerPointAward::create([
                'user_id'     => $pointRequest->user_id,
                'category'    => $pointRequest->category_key,
                'points'      => $finalPoints,
                'reason'      => $pointRequest->reason . ($finalPoints !== $pointRequest->points_requested ? " (عُدلت من {$pointRequest->points_requested} بواسطة HR)" : ''),
                'note'        => $notes,
                'awarded_by'  => $actor->id,
                'source_type' => 'volunteer_point_request',
                'source_id'   => $pointRequest->id,
                'awarded_at'  => now(),
            ]);

            // 2. تحديث الطلب إلى معتمد
            $pointRequest->update([
                'status'           => VolunteerPointRequest::STATUS_APPROVED,
                'points_approved'  => $finalPoints,
                'reviewer_id'      => $actor->id,
                'reviewed_at'      => now(),
                'review_notes'     => $notes,
                'award_id'         => $award->id,
            ]);

            // 3. توثيق الاعتماد في سجل الرقابة
            AuditLogService::log(
                action: 'VOLUNTEER_POINTS_APPROVED',
                entityType: 'VolunteerPointRequest',
                entityId: $pointRequest->id,
                entityName: "اعتماد نقاط {$pointRequest->activity_name}",
                oldValues: ['status' => 'pending', 'points' => $pointRequest->points_requested],
                newValues: ['status' => 'approved', 'points' => $finalPoints],
                extra: [
                    'user_id'     => $pointRequest->user_id,
                    'requester_id'=> $pointRequest->requester_id,
                    'award_id'    => $award->id,
                ],
                description: "تم اعتماد منح {$finalPoints} نقطة للمتطوع #{$pointRequest->user_id} بواسطة {$actor->name}."
            );

            return $award;
        });

        return response()->json([
            'success' => true,
            'message' => 'تم اعتماد طلب النقاط بنجاح وإضافتها إلى رصيد المتطوع.',
            'data'    => [
                'request' => $pointRequest->fresh(['user:id,name', 'reviewer:id,name']),
                'award'   => $award,
            ],
        ]);
    }

    /** رفض طلب النقاط مع تسجيل السبب الإلزامي */
    public function reject(Request $request, VolunteerPointRequest $pointRequest): JsonResponse
    {
        $actor = $request->user();

        if (! $this->canApprove($actor)) {
            return response()->json(['success' => false, 'message' => 'رفض طلبات النقاط متاح لإدارة الموارد البشرية فقط.'], 403);
        }

        if ($pointRequest->status === VolunteerPointRequest::STATUS_APPROVED) {
            return response()->json(['success' => false, 'message' => 'لا يمكن رفض طلب تم اعتماده مسبقاً.'], 422);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|min:4|max:1000',
        ], [
            'rejection_reason.required' => 'سبب الرفض إلزامي لتوضيحه لمقدم الطلب.',
            'rejection_reason.min'      => 'يرجى كتابة سبب رفض واضح بحد أدنى 4 أحرف.',
        ]);

        $pointRequest->update([
            'status'           => VolunteerPointRequest::STATUS_REJECTED,
            'reviewer_id'      => $actor->id,
            'reviewed_at'      => now(),
            'rejection_reason' => trim($validated['rejection_reason']),
        ]);

        AuditLogService::log(
            action: 'VOLUNTEER_POINTS_REJECTED',
            entityType: 'VolunteerPointRequest',
            entityId: $pointRequest->id,
            entityName: "رفض نقاط {$pointRequest->activity_name}",
            oldValues: ['status' => 'pending'],
            newValues: ['status' => 'rejected'],
            extra: ['rejection_reason' => $validated['rejection_reason']],
            description: "تم رفض طلب النقاط للمتطوع #{$pointRequest->user_id} مع توثيق السبب."
        );

        return response()->json([
            'success' => true,
            'message' => 'تم رفض طلب النقاط وتسجيل سبب الرفض.',
            'data'    => $pointRequest->fresh(['user:id,name', 'reviewer:id,name']),
        ]);
    }

    /** إعادة الطلب للتعديل */
    public function returnForRevision(Request $request, VolunteerPointRequest $pointRequest): JsonResponse
    {
        $actor = $request->user();

        if (! $this->canApprove($actor)) {
            return response()->json(['success' => false, 'message' => 'متاح لإدارة الموارد البشرية فقط.'], 403);
        }

        $validated = $request->validate([
            'review_notes' => 'required|string|min:4|max:1000',
        ]);

        $pointRequest->update([
            'status'       => VolunteerPointRequest::STATUS_REVISION,
            'reviewer_id'  => $actor->id,
            'reviewed_at'  => now(),
            'review_notes' => trim($validated['review_notes']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تمت إعادة الطلب للمعدّ مع إرفاق الملاحظات المطلوبة للتعديل.',
            'data'    => $pointRequest->fresh(['user:id,name', 'reviewer:id,name']),
        ]);
    }
}
