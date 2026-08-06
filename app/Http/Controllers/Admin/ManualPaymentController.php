<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\ManualPayment;
use App\Models\Registration;
use App\Models\Workshop;
use App\Services\AuditLogService;
use App\Services\Finance\ManualPaymentConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ManualPaymentController extends Controller
{
    // GET /finance/manual-payments
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ManualPayment::class);

        $query = ManualPayment::with(['student', 'financeAccount', 'reviewer'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->student_id, fn ($q) => $q->where('student_id', $request->student_id))
            ->when($request->finance_account_id, fn ($q) => $q->where('finance_account_id', $request->finance_account_id))
            ->when($request->currency, fn ($q) => $q->where('currency', $request->currency))
            ->when($request->payment_method, fn ($q) => $q->where('payment_method', $request->payment_method))
            ->when($request->date_from, fn ($q) => $q->whereDate('payment_date', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('payment_date', '<=', $request->date_to))
            ->when($request->external_reference, fn ($q) => $q->where('external_reference', 'like', '%' . $request->external_reference . '%'))
            ->when($request->search, function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($inner) use ($s) {
                    $inner->where('external_reference', 'like', "%{$s}%")
                          ->orWhereHas('student', fn ($sq) => $sq->where('name', 'like', "%{$s}%")
                              ->orWhere('email', 'like', "%{$s}%")
                              ->orWhere('phone', 'like', "%{$s}%"));
                });
            })
            ->orderByDesc('payment_date')
            ->orderByDesc('id');

        $paginated = $query->paginate(30);

        $data = $paginated->through(fn ($mp) => $this->formatRow($mp));

        return response()->json(['success' => true, 'data' => $data]);
    }

    // POST /finance/manual-payments
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ManualPayment::class);

        $data = $request->validate([
            'student_id'         => 'required|exists:users,id',
            'purchasable_type'   => 'required|string|in:course,workshop,learning_path,App\Models\Course,App\Models\Workshop,App\Models\LearningPath',
            'purchasable_id'     => 'required|integer|min:1',
            'finance_account_id' => 'required|exists:finance_accounts,id',
            'expected_amount'    => 'required|numeric|min:0',
            'paid_amount'        => 'required|numeric|min:0',
            'payment_method'     => 'nullable|in:bank_transfer,cash,wise,other',
            'external_reference' => 'nullable|string|max:191',
            'payment_date'       => 'required|date',
            'notes'              => 'nullable|string|max:2000',
            'proof'              => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:5120',
        ]);

        // Currency is always derived from the destination finance account — never trusted from the client.
        $financeAccount = \App\Models\FinanceAccount::findOrFail($data['finance_account_id']);
        $currency = $financeAccount->currency;

        $typeMap = [
            'course'        => 'App\Models\Course',
            'workshop'      => 'App\Models\Workshop',
            'learning_path' => 'App\Models\LearningPath',
        ];
        $purchasableType = $typeMap[$data['purchasable_type']] ?? $data['purchasable_type'];

        $proofPath = null;
        if ($request->hasFile('proof')) {
            $file = $request->file('proof');
            $proofPath = Storage::disk('private')->putFileAs(
                'manual-payment-proofs',
                $file,
                Str::uuid() . '.' . $file->extension()
            );
        }

        $mp = ManualPayment::create([
            'student_id'         => $data['student_id'],
            'purchasable_type'   => $purchasableType,
            'purchasable_id'     => $data['purchasable_id'],
            'finance_account_id' => $data['finance_account_id'],
            'expected_amount'    => $data['expected_amount'],
            'paid_amount'        => $data['paid_amount'],
            'difference_amount'  => $data['paid_amount'] - $data['expected_amount'],
            'currency'           => $currency,
            'payment_method'     => $data['payment_method'] ?? null,
            'external_reference' => $data['external_reference'] ?? null,
            'proof_path'         => $proofPath,
            'status'             => 'pending_review',
            'payment_date'       => $data['payment_date'],
            'notes'              => $data['notes'] ?? null,
            'created_by'         => $request->user()?->id,
            'updated_by'         => $request->user()?->id,
        ]);

        AuditLogService::log(
            'manual_payment.created',
            'ManualPayment',
            $mp->id,
            "Manual Payment #{$mp->id}",
            null,
            $mp->toArray(),
        );

        return response()->json(['success' => true, 'data' => $mp->load(['student', 'financeAccount'])], 201);
    }

    // GET /finance/manual-payments/{manualPayment}
    public function show(ManualPayment $manualPayment): JsonResponse
    {
        $this->authorize('view', $manualPayment);

        $manualPayment->load([
            'student', 'financeAccount', 'order', 'payment',
            'financeTransaction', 'reviewer', 'creator',
        ]);

        return response()->json(['success' => true, 'data' => $manualPayment]);
    }

    // PATCH /finance/manual-payments/{manualPayment}
    public function update(Request $request, ManualPayment $manualPayment): JsonResponse
    {
        $this->authorize('update', $manualPayment);

        $data = $request->validate([
            'expected_amount'    => 'sometimes|numeric|min:0',
            'paid_amount'        => 'sometimes|numeric|min:0',
            'payment_method'     => 'nullable|in:bank_transfer,cash,wise,other',
            'external_reference' => 'nullable|string|max:191',
            'payment_date'       => 'sometimes|date',
            'notes'              => 'nullable|string|max:2000',
            'proof'              => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:5120',
        ]);

        if ($request->hasFile('proof')) {
            $file = $request->file('proof');
            if ($manualPayment->proof_path) {
                Storage::disk('private')->delete($manualPayment->proof_path);
            }
            $data['proof_path'] = Storage::disk('private')->putFileAs(
                'manual-payment-proofs',
                $file,
                Str::uuid() . '.' . $file->extension()
            );
        }

        unset($data['proof']);

        // Recalculate difference if amounts changed
        $paidAmount     = $data['paid_amount']     ?? (float) $manualPayment->paid_amount;
        $expectedAmount = $data['expected_amount'] ?? (float) $manualPayment->expected_amount;
        $data['difference_amount'] = $paidAmount - $expectedAmount;
        $data['updated_by']        = $request->user()?->id;

        $manualPayment->update($data);

        return response()->json(['success' => true, 'data' => $manualPayment->fresh()->load(['student', 'financeAccount'])]);
    }

    // POST /finance/manual-payments/{manualPayment}/confirm
    public function confirm(Request $request, ManualPayment $manualPayment): JsonResponse
    {
        $this->authorize('confirm', $manualPayment);

        try {
            $result = app(ManualPaymentConfirmationService::class)->confirm(
                $manualPayment,
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment confirmed and student registered.',
                'data'    => [
                    'order_id'        => $result['order']->id,
                    'payment_id'      => $result['payment']->id,
                    'registration_id' => $result['registration']?->id,
                    'transaction_id'  => $result['transaction']->id,
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // POST /finance/manual-payments/{manualPayment}/reject
    public function reject(Request $request, ManualPayment $manualPayment): JsonResponse
    {
        $this->authorize('reject', $manualPayment);

        $data = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        $old = $manualPayment->only(['status', 'notes']);

        $manualPayment->update([
            'status'      => 'rejected',
            'notes'       => ($data['notes'] ?? null) ? $manualPayment->notes . "\n[Rejection reason]: " . $data['notes'] : $manualPayment->notes,
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
            'updated_by'  => $request->user()?->id,
        ]);

        AuditLogService::log(
            'manual_payment.rejected',
            'ManualPayment',
            $manualPayment->id,
            "Manual Payment #{$manualPayment->id}",
            $old,
            ['status' => 'rejected'],
        );

        return response()->json(['success' => true, 'message' => 'Payment rejected.', 'data' => $manualPayment->fresh()]);
    }

    // POST /finance/manual-payments/{manualPayment}/cancel
    public function cancel(Request $request, ManualPayment $manualPayment): JsonResponse
    {
        $this->authorize('cancel', $manualPayment);

        $old = $manualPayment->only(['status']);

        $manualPayment->update([
            'status'     => 'cancelled',
            'updated_by' => $request->user()?->id,
        ]);

        AuditLogService::log(
            'manual_payment.cancelled',
            'ManualPayment',
            $manualPayment->id,
            "Manual Payment #{$manualPayment->id}",
            $old,
            ['status' => 'cancelled'],
        );

        return response()->json(['success' => true, 'message' => 'Payment cancelled.', 'data' => $manualPayment->fresh()]);
    }

    // GET /finance/manual-payments/{manualPayment}/proof
    public function downloadProof(ManualPayment $manualPayment): mixed
    {
        $this->authorize('view', $manualPayment);

        if (! $manualPayment->proof_path || ! Storage::disk('private')->exists($manualPayment->proof_path)) {
            return response()->json(['success' => false, 'message' => 'No proof file found.'], 404);
        }

        AuditLogService::log(
            'manual_payment.proof_downloaded',
            'ManualPayment',
            $manualPayment->id,
            "Manual Payment #{$manualPayment->id}",
        );

        return Storage::disk('private')->download($manualPayment->proof_path);
    }

    // GET /finance/purchasables?q={query}&type=all|course|workshop&student_id={id}&per_page=30
    public function searchPurchasables(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ManualPayment::class);

        $q         = trim((string) $request->get('q', ''));
        $type      = (string) $request->get('type', 'all');
        $studentId = (int) $request->get('student_id', 0);
        $perPage   = min((int) $request->get('per_page', 30), 100);

        $results = collect();

        // ── Paid courses ──────────────────────────────────────────────────────
        if ($type === 'all' || $type === 'course') {
            $courses = Course::query()
                ->select(['id', 'title', 'slug', 'price', 'status'])
                ->where('price', '>', 0)
                ->whereNull('deleted_at')
                ->when($q !== '', function ($query) use ($q) {
                    $escaped = '%' . addcslashes($q, '%_\\') . '%';
                    $query->where(function ($inner) use ($escaped) {
                        $inner->where('title', 'like', $escaped)
                              ->orWhere('slug', 'like', $escaped);
                    });
                })
                ->orderBy('title')
                ->limit($perPage)
                ->get();

            // Eager-load enrollment status for the selected student
            $enrolledCourseIds = $studentId
                ? Registration::where('user_id', $studentId)
                    ->whereNotNull('course_id')
                    ->whereIn('status', ['registered', 'active', 'pending'])
                    ->pluck('course_id')
                    ->flip()
                : collect();

            foreach ($courses as $c) {
                $results->push([
                    'id'              => $c->id,
                    'type'            => 'course',
                    'title'           => $c->title,
                    'slug'            => $c->slug,
                    'price'           => (float) $c->price,
                    'currency'        => 'EUR',
                    'status'          => $c->status,
                    'is_paid'         => true,
                    'student_relation' => [
                        'is_enrolled'    => $studentId ? $enrolledCourseIds->has($c->id) : null,
                        'payment_status' => null,
                    ],
                ]);
            }
        }

        // ── Paid workshops (price from parent course) ─────────────────────────
        if ($type === 'all' || $type === 'workshop') {
            $workshops = Workshop::query()
                ->join('courses', 'workshops.course_id', '=', 'courses.id')
                ->select([
                    'workshops.id', 'workshops.title', 'workshops.slug',
                    'workshops.status', 'courses.price',
                ])
                ->where('courses.price', '>', 0)
                ->whereNull('workshops.deleted_at')
                ->whereNull('courses.deleted_at')
                ->when($q !== '', function ($query) use ($q) {
                    $escaped = '%' . addcslashes($q, '%_\\') . '%';
                    $query->where(function ($inner) use ($escaped) {
                        $inner->where('workshops.title', 'like', $escaped)
                              ->orWhere('workshops.slug', 'like', $escaped);
                    });
                })
                ->orderBy('workshops.title')
                ->limit($perPage)
                ->get();

            $enrolledWorkshopIds = $studentId
                ? Registration::where('user_id', $studentId)
                    ->whereNotNull('workshop_id')
                    ->whereIn('status', ['registered', 'active', 'pending'])
                    ->pluck('workshop_id')
                    ->flip()
                : collect();

            foreach ($workshops as $w) {
                $results->push([
                    'id'              => $w->id,
                    'type'            => 'workshop',
                    'title'           => $w->title,
                    'slug'            => $w->slug,
                    'price'           => (float) $w->price,
                    'currency'        => 'EUR',
                    'status'          => $w->status,
                    'is_paid'         => true,
                    'student_relation' => [
                        'is_enrolled'    => $studentId ? $enrolledWorkshopIds->has($w->id) : null,
                        'payment_status' => null,
                    ],
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $results->values(),
            'meta'    => [
                'total'        => $results->count(),
                'current_page' => 1,
                'last_page'    => 1,
                'per_page'     => $perPage,
            ],
        ]);
    }

    // GET /finance/students/search?q={query}
    public function searchStudents(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ManualPayment::class);

        $q = (string) $request->get('q', '');
        if (mb_strlen(trim($q)) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $students = \App\Models\User::where(function ($query) use ($q) {
                $escaped = '%' . addcslashes($q, '%_\\') . '%';
                $query->where('name', 'like', $escaped)
                      ->orWhere('email', 'like', $escaped)
                      ->orWhere('phone', 'like', $escaped);
            })
            ->where('role', 'student')
            ->select(['id', 'name', 'email', 'phone', 'is_active', 'avatar'])
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(fn ($u) => [
                'id'         => $u->id,
                'student_id' => 'STU-' . str_pad($u->id, 4, '0', STR_PAD_LEFT),
                'name'       => $u->name,
                'email'      => $u->email,
                'phone'      => $u->phone ?? null,
                'avatar'     => $u->avatar_url ?? null,
                'status'     => $u->is_active ? 'active' : 'inactive',
            ]);

        return response()->json(['success' => true, 'data' => $students]);
    }

    // GET /finance/manual-payments/stats
    public function stats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ManualPayment::class);

        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd   = $now->copy()->endOfMonth();

        $pending = ManualPayment::where('status', 'pending_review')->count();
        $confirmedThisMonth = ManualPayment::where('status', 'confirmed')
            ->whereBetween('payment_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->count();
        $confirmedTotal = ManualPayment::where('status', 'confirmed')->sum('paid_amount');
        $rejected = ManualPayment::where('status', 'rejected')->count();
        $avgPayment = ManualPayment::where('status', 'confirmed')->avg('paid_amount') ?? 0;

        return response()->json([
            'success' => true,
            'data' => [
                'pending_review'        => $pending,
                'confirmed_this_month'  => $confirmedThisMonth,
                'confirmed_total'       => round((float) $confirmedTotal, 2),
                'rejected'              => $rejected,
                'avg_payment'           => round((float) $avgPayment, 2),
            ],
        ]);
    }

    private function formatRow(ManualPayment $mp): array
    {
        $purchasableTitle = null;
        $purchasableType  = null;

        try {
            $item             = $mp->purchasable()->first();
            $purchasableTitle = $item?->title ?? $item?->name ?? null;
            $purchasableType  = match (true) {
                str_ends_with($mp->purchasable_type, 'Course')       => 'course',
                str_ends_with($mp->purchasable_type, 'Workshop')     => 'workshop',
                str_ends_with($mp->purchasable_type, 'LearningPath') => 'learning_path',
                default                                               => 'unknown',
            };
        } catch (\Throwable) {
        }

        return [
            'id'                 => $mp->id,
            'status'             => $mp->status,
            'paid_amount'        => (float) $mp->paid_amount,
            'expected_amount'    => (float) $mp->expected_amount,
            'difference_amount'  => (float) $mp->difference_amount,
            'currency'           => $mp->currency,
            'payment_method'     => $mp->payment_method,
            'external_reference' => $mp->external_reference,
            'payment_date'       => $mp->payment_date?->toDateString(),
            'created_at'         => $mp->created_at?->toIso8601String(),
            'student'            => $mp->student ? [
                'id'    => $mp->student->id,
                'name'  => $mp->student->name,
                'email' => $mp->student->email,
                'phone' => $mp->student->phone ?? null,
            ] : null,
            'purchasable' => [
                'id'    => $mp->purchasable_id,
                'title' => $purchasableTitle,
                'type'  => $purchasableType,
            ],
            'account' => $mp->financeAccount ? [
                'id'       => $mp->financeAccount->id,
                'name'     => $mp->financeAccount->name,
                'currency' => $mp->financeAccount->currency,
            ] : null,
            'reviewer'    => $mp->reviewer ? ['id' => $mp->reviewer->id, 'name' => $mp->reviewer->name] : null,
            'reviewed_at' => $mp->reviewed_at?->toIso8601String(),
        ];
    }
}
