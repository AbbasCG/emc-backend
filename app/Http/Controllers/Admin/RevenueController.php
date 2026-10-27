<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountJournalEntry;
use App\Models\ManualRevenue;
use App\Models\Course;
use App\Services\AuditLogService;
use App\Services\Finance\VoucherAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class RevenueController extends Controller
{
    private const ATTACHMENT_DIR = 'receipt_vouchers';

    /** Same set FinanceAccountController accepts. */
    private const CURRENCIES = ['EUR', 'USD', 'YER', 'SAR', 'TRY', 'GBP'];

    public function __construct(private readonly VoucherAttachmentService $attachments) {}

    /**
     * Get list of manual revenues with filtering and summary metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ManualRevenue::class);

        $query = ManualRevenue::with([
            'revenueAccount',
            'depositAccount',
            'program',
            'creator',
            'journalEntries.account',
        ]);

        // Search filter
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('receipt_no', 'like', "%{$search}%")
                  ->orWhere('payer_name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('program_name', 'like', "%{$search}%");
            });
        }

        // Date range
        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Revenue Account filter
        if ($request->filled('revenue_account_id')) {
            $query->where('revenue_account_id', $request->input('revenue_account_id'));
        }

        // Deposit Account filter
        if ($request->filled('deposit_account_id')) {
            $query->where('deposit_account_id', $request->input('deposit_account_id'));
        }

        // Program filter
        if ($request->filled('program_id')) {
            $query->where('program_id', $request->input('program_id'));
        }

        $query->orderBy('date', 'desc')->orderBy('id', 'desc');

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $revenues = $query->paginate($perPage);

        // Summary statistics
        $totalAmount = (float) ManualRevenue::where('status', 'posted')->sum('amount');
        $totalCount = ManualRevenue::where('status', 'posted')->count();

        $byCategory = ManualRevenue::where('manual_revenues.status', 'posted')
            ->join('accounts', 'manual_revenues.revenue_account_id', '=', 'accounts.id')
            ->select('accounts.name_ar as category_name', 'accounts.code as category_code', 'manual_revenues.currency', DB::raw('SUM(manual_revenues.amount) as total'))
            ->groupBy('accounts.name_ar', 'accounts.code', 'manual_revenues.currency')
            ->toBase()
            ->get();

        // Amounts in different currencies are never added together.
        $byCurrency = ManualRevenue::where('status', 'posted')
            ->select('currency', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('currency')
            ->orderBy('currency')
            ->toBase()
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $revenues,
            'summary' => [
                'total_amount' => $totalAmount,
                'total_count'  => $totalCount,
                'by_category'  => $byCategory,
                'by_currency'  => $byCurrency,
            ],
        ]);
    }

    /**
     * Auto-generate the next receipt voucher number (e.g. RV-2026-0001).
     */
    public function suggestReceiptNo(): JsonResponse
    {
        $this->authorize('create', ManualRevenue::class);

        $year = date('Y');
        $prefix = "RV-{$year}-";

        $lastReceipt = ManualRevenue::where('receipt_no', 'like', "{$prefix}%")
            ->orderBy('id', 'desc')
            ->first();

        if ($lastReceipt) {
            $seqStr = str_replace($prefix, '', $lastReceipt->receipt_no);
            $seq = is_numeric($seqStr) ? ((int) $seqStr + 1) : 1;
        } else {
            $seq = 1;
        }

        $suggested = $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

        return response()->json([
            'success'      => true,
            'suggested_no' => $suggested,
        ]);
    }

    /**
     * Store a new manual receipt voucher & generate balanced journal entries.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ManualRevenue::class);

        $validated = $request->validate([
            'receipt_no'         => ['nullable', 'string', 'max:50', 'unique:manual_revenues,receipt_no'],
            'date'               => ['required', 'date'],
            'revenue_account_id' => ['required', 'exists:accounts,id'],
            'deposit_account_id' => ['nullable', 'exists:accounts,id', 'different:revenue_account_id'], // optional — auto-resolved from currency
            'amount'             => ['required', 'numeric', 'gt:0'],
            'currency'           => ['nullable', 'string', 'in:'.implode(',', self::CURRENCIES)],
            'payer_name'         => ['required', 'string', 'max:255'],
            'program_id'         => ['nullable', 'integer'],
            'program_name'       => ['nullable', 'string', 'max:255'],
            'description'        => ['required', 'string'],
            'status'             => ['nullable', 'in:posted,draft,cancelled'],
            'attachment'         => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'], // 10MB max
        ]);

        $user     = $request->user();
        $currency = strtoupper($validated['currency'] ?? 'USD');

        // Auto-resolve deposit account from currency default if not provided
        $depositAccountId = $validated['deposit_account_id'] ?? null;
        if (empty($depositAccountId)) {
            $defaultAccount = \App\Models\Account::defaultForCurrency($currency);
            if (!$defaultAccount) {
                return response()->json([
                    'success' => false,
                    'message' => "لم يتم تحديد حساب إيداع افتراضي للعملة {$currency}. يرجى تحديده من إعدادات دليل الحسابات.",
                ], 422);
            }
            $depositAccountId = $defaultAccount->id;
        }
        $validated['deposit_account_id'] = $depositAccountId;

        $this->assertPostableAccounts($validated);

        // Autogenerate receipt_no if not provided
        if (empty($validated['receipt_no'])) {
            $year = date('Y', strtotime($validated['date']));
            $prefix = "RV-{$year}-";
            $lastReceipt = ManualRevenue::where('receipt_no', 'like', "{$prefix}%")
                ->orderBy('id', 'desc')
                ->first();
            $seq = $lastReceipt ? ((int) str_replace($prefix, '', $lastReceipt->receipt_no) + 1) : 1;
            $receiptNo = $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        } else {
            $receiptNo = $validated['receipt_no'];
        }

        // Handle attachment file upload
        $attachmentPath = null;
        $attachmentName = null;
        if ($request->hasFile('attachment')) {
            [$attachmentPath, $attachmentName] = $this->attachments->store($request->file('attachment'), self::ATTACHMENT_DIR);
        }

        // Lookup program name if program_id provided
        $programName = $validated['program_name'] ?? null;
        if (!empty($validated['program_id'])) {
            $prog = Course::find($validated['program_id']);
            if ($prog) {
                $programName = $prog->title_ar ?? $prog->title ?? $prog->name;
            }
        }

        $revenueStatus = $validated['status'] ?? 'posted';

        try {
            $revenue = DB::transaction(function () use ($validated, $receiptNo, $attachmentPath, $attachmentName, $programName, $revenueStatus, $currency, $depositAccountId, $user) {
                $manualRevenue = ManualRevenue::create([
                    'receipt_no'         => $receiptNo,
                    'date'               => $validated['date'],
                    'revenue_account_id' => $validated['revenue_account_id'],
                    'deposit_account_id' => $depositAccountId,
                    'amount'             => $validated['amount'],
                    'currency'           => $currency,
                    'payer_name'         => $validated['payer_name'],
                    'program_id'         => $validated['program_id'] ?? null,
                    'program_name'       => $programName,
                    'description'        => $validated['description'],
                    'attachment_path'    => $attachmentPath,
                    'attachment_name'    => $attachmentName,
                    'status'             => $revenueStatus,
                    'created_by'         => $user?->id,
                ]);

                // If posted, generate the double-entry journal entries
                if ($revenueStatus === 'posted') {
                    $this->createJournalEntries($manualRevenue);
                }

                return $manualRevenue;
            });
        } catch (\Throwable $e) {
            $this->attachments->delete($attachmentPath, self::ATTACHMENT_DIR);
            throw $e;
        }

        $revenue->load(['revenueAccount', 'depositAccount', 'program', 'creator', 'journalEntries.account']);

        AuditLogService::logCreate($revenue, "سجّل سند قبض إيراد جديد برقم: {$revenue->receipt_no} من ({$revenue->payer_name}) بمبلغ {$revenue->amount} {$revenue->currency}");

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل سند القبض بنجاح وترحيل القيد المحاسبي المزدوج',
            'data'    => $revenue,
        ], 201);
    }

    /**
     * Show single revenue receipt voucher details.
     */
    public function show(int $id): JsonResponse
    {
        $revenue = ManualRevenue::with([
            'revenueAccount',
            'depositAccount',
            'program',
            'creator',
            'updater',
            'journalEntries.account',
        ])->findOrFail($id);

        $this->authorize('view', $revenue);

        return response()->json([
            'success' => true,
            'data'    => $revenue,
        ]);
    }

    /**
     * Update a draft receipt voucher. Posting a draft generates its journal
     * entries; a posted voucher is immutable and is refused with a 422.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $revenue = ManualRevenue::findOrFail($id);
        $this->authorize('update', $revenue);
        $this->assertNotPosted($revenue);

        $oldData = $revenue->toArray();

        $validated = $request->validate([
            'date'               => ['required', 'date'],
            'revenue_account_id' => ['required', 'exists:accounts,id'],
            'deposit_account_id' => ['nullable', 'exists:accounts,id', 'different:revenue_account_id'], // optional — auto-resolved
            'amount'             => ['required', 'numeric', 'gt:0'],
            'currency'           => ['nullable', 'string', 'in:'.implode(',', self::CURRENCIES)],
            'payer_name'         => ['required', 'string', 'max:255'],
            'program_id'         => ['nullable', 'integer'],
            'program_name'       => ['nullable', 'string', 'max:255'],
            'description'        => ['required', 'string'],
            'status'             => ['required', 'in:posted,draft,cancelled'],
            'attachment'         => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);

        $user     = $request->user();
        $currency = strtoupper($validated['currency'] ?? $revenue->currency ?? 'USD');

        // Auto-resolve deposit account if not explicitly supplied
        $depositAccountId = $validated['deposit_account_id'] ?? null;
        if (empty($depositAccountId)) {
            $defaultAccount = \App\Models\Account::defaultForCurrency($currency);
            if (!$defaultAccount) {
                return response()->json([
                    'success' => false,
                    'message' => "لم يتم تحديد حساب إيداع افتراضي للعملة {$currency}.",
                ], 422);
            }
            $depositAccountId = $defaultAccount->id;
        }
        $validated['deposit_account_id'] = $depositAccountId;

        $this->assertPostableAccounts($validated);

        // Store a replacement attachment; the old file is removed only once the update commits.
        $oldAttachment = null;
        $newAttachment = null;
        if ($request->hasFile('attachment')) {
            $oldAttachment = $revenue->attachment_path;
            [$newAttachment, $revenue->attachment_name] = $this->attachments->store($request->file('attachment'), self::ATTACHMENT_DIR);
            $revenue->attachment_path = $newAttachment;
        }

        // Lookup program name if program_id provided
        $programName = $validated['program_name'] ?? null;
        if (!empty($validated['program_id'])) {
            $prog = Course::find($validated['program_id']);
            if ($prog) {
                $programName = $prog->title_ar ?? $prog->title ?? $prog->name;
            }
        }

        try {
            DB::transaction(function () use ($revenue, $validated, $programName, $depositAccountId, $currency, $user) {
                $revenue->update([
                    'date'               => $validated['date'],
                    'revenue_account_id' => $validated['revenue_account_id'],
                    'deposit_account_id' => $depositAccountId,
                    'amount'             => $validated['amount'],
                    'currency'           => $currency,
                    'payer_name'         => $validated['payer_name'],
                    'program_id'         => $validated['program_id'] ?? null,
                    'program_name'       => $programName,
                    'description'        => $validated['description'],
                    'status'             => $validated['status'],
                    'updated_by'         => $user?->id,
                ]);

                // A draft has no journal entries; posting it creates them once.
                if ($revenue->status === 'posted') {
                    $this->createJournalEntries($revenue);
                }
            });
        } catch (\Throwable $e) {
            $this->attachments->delete($newAttachment, self::ATTACHMENT_DIR);
            throw $e;
        }

        if ($oldAttachment) {
            $this->attachments->delete($oldAttachment, self::ATTACHMENT_DIR);
        }

        $revenue->load(['revenueAccount', 'depositAccount', 'program', 'creator', 'updater', 'journalEntries.account']);

        AuditLogService::logUpdate($revenue, $oldData, $revenue->fresh()->toArray(), "عدّل بيانات سند القبض ({$revenue->receipt_no})");

        return response()->json([
            'success' => true,
            'message' => $revenue->status === 'posted' ? 'تم تحديث سند القبض وترحيل القيد المحاسبي' : 'تم تحديث سند القبض',
            'data'    => $revenue,
        ]);
    }

    /**
     * Delete a draft or cancelled receipt voucher. Posted vouchers are never
     * hard-deleted — their journal entries are part of the ledger.
     */
    public function destroy(int $id): JsonResponse
    {
        $revenue = ManualRevenue::findOrFail($id);
        $this->authorize('delete', $revenue);
        $this->assertNotPosted($revenue);

        $receiptNo = $revenue->receipt_no;
        $attachmentPath = $revenue->attachment_path;

        $revenue->delete();
        $this->attachments->delete($attachmentPath, self::ATTACHMENT_DIR);

        AuditLogService::logDelete($revenue, "حذف مسودة سند القبض ({$receiptNo})");

        return response()->json([
            'success' => true,
            'message' => 'تم حذف سند القبض بنجاح',
        ]);
    }

    /**
     * Download the voucher's attachment from private storage (audited).
     */
    public function downloadAttachment(int $id): Response
    {
        return $this->attachmentResponse($id, false);
    }

    /**
     * Inline preview of the voucher's attachment from private storage (audited).
     */
    public function previewAttachment(int $id): Response
    {
        return $this->attachmentResponse($id, true);
    }

    private function attachmentResponse(int $id, bool $inline): Response
    {
        $revenue = ManualRevenue::findOrFail($id);
        $this->authorize('downloadAttachment', $revenue);

        return $this->attachments->respond($revenue, self::ATTACHMENT_DIR, "سند القبض {$revenue->receipt_no}", $inline);
    }

    private function assertNotPosted(ManualRevenue $revenue): void
    {
        if ($revenue->isPosted()) {
            throw ValidationException::withMessages([
                'status' => 'سند القبض مُرحّل محاسبياً ولا يمكن تعديله أو حذفه. لتصحيحه سجّل سنداً عكسياً.',
            ]);
        }
    }

    /**
     * Journal lines may only post to leaf (selectable) accounts, and the
     * revenue side must be a credit-nature account.
     */
    private function assertPostableAccounts(array $validated): void
    {
        $accounts = Account::whereIn('id', [$validated['revenue_account_id'], $validated['deposit_account_id']])
            ->get()->keyBy('id');
        $revenueAccount = $accounts->get((int) $validated['revenue_account_id']);
        $depositAccount = $accounts->get((int) $validated['deposit_account_id']);

        $errors = [];
        if (! $revenueAccount?->is_selectable || $revenueAccount->type !== 'credit') {
            $errors['revenue_account_id'] = 'حساب الإيراد يجب أن يكون حساباً فرعياً قابلاً للترحيل ومن طبيعة دائنة.';
        }
        if (! $depositAccount?->is_selectable) {
            $errors['deposit_account_id'] = 'حساب الإيداع يجب أن يكون حساباً فرعياً قابلاً للترحيل.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Get paid courses list (with price > 0) for revenue course selection.
     */
    public function getPaidCourses(): JsonResponse
    {
        $courses = Course::where(function ($q) {
                $q->where('is_paid', true)
                  ->orWhere('type', 'paid')
                  ->orWhere('price', '>', 0);
            })
            ->whereNull('deleted_at')
            ->select('id', 'title', 'price', 'currency', 'status')
            ->orderBy('title')
            ->get()
            ->map(fn($c) => [
                'id'       => $c->id,
                'title'    => $c->title,
                'price'    => (float) $c->price,
                'currency' => $c->currency ?? 'USD',
                'status'   => $c->status,
            ]);

        return response()->json([
            'success' => true,
            'data'    => $courses,
        ]);
    }

    /**
     * Get students registered in a specific paid course who have not completed payment.
     * "Incomplete payment" means registration status = pending_payment.
     */
    public function getCourseStudentsPendingPayment(int $courseId): JsonResponse
    {
        $course = Course::findOrFail($courseId);

        $registrations = \App\Models\Registration::with(['user'])
            ->where('course_id', $courseId)
            ->where('status', \App\Enums\RegistrationStatus::PENDING_PAYMENT->value)
            ->whereNull('deleted_at')
            ->get()
            ->map(fn($reg) => [
                'registration_id' => $reg->id,
                'user_id'         => $reg->user_id,
                'full_name'       => $reg->full_name ?? $reg->user?->name ?? 'غير محدد',
                'email'           => $reg->email ?? $reg->user?->email ?? '',
                'phone'           => $reg->phone ?? '',
                'course_price'    => (float) $course->price,
                'currency'        => $course->currency ?? 'USD',
                'status'          => $reg->status,
            ]);

        return response()->json([
            'success' => true,
            'course'  => [
                'id'    => $course->id,
                'title' => $course->title,
                'price' => (float) $course->price,
                'currency' => $course->currency ?? 'USD',
            ],
            'data'    => $registrations,
        ]);
    }

    /**
     * Get cashbox/fund current balance (total posted revenues - total posted expenses).
     * This is used to validate that expenses don't exceed available balance.
     */
    public function getCashboxBalance(): JsonResponse
    {
        $totalRevenues = (float) \App\Models\ManualRevenue::where('status', 'posted')->sum('amount');
        $totalExpenses = (float) \App\Models\ManualExpense::where('status', 'posted')->sum('amount');
        $balance       = $totalRevenues - $totalExpenses;

        return response()->json([
            'success'        => true,
            'total_revenues' => $totalRevenues,
            'total_expenses' => $totalExpenses,
            'balance'        => $balance,
        ]);
    }

    /**
     * Helper to create double-entry journal entries for a receipt voucher.
     */
    private function createJournalEntries(ManualRevenue $revenue): void
    {
        // 1. Debit Entry (من حـ/ حساب الإيداع البنك أو المحفظة - مدين) -> زيادة في الأصول
        AccountJournalEntry::create([
            'voucher_no'        => $revenue->receipt_no,
            'transaction_type'  => 'revenue',
            'manual_revenue_id' => $revenue->id,
            'account_id'        => $revenue->deposit_account_id,
            'entry_type'        => 'debit',
            'debit_amount'      => $revenue->amount,
            'credit_amount'     => 0.00,
            'currency'          => $revenue->currency,
            'date'              => $revenue->date,
            'description'       => "سند قبض رقم {$revenue->receipt_no} من {$revenue->payer_name} - {$revenue->description}",
            'created_by'        => $revenue->created_by,
        ]);

        // 2. Credit Entry (إلى حـ/ حساب الإيراد - دائن) -> زيادة في الإيرادات
        AccountJournalEntry::create([
            'voucher_no'        => $revenue->receipt_no,
            'transaction_type'  => 'revenue',
            'manual_revenue_id' => $revenue->id,
            'account_id'        => $revenue->revenue_account_id,
            'entry_type'        => 'credit',
            'debit_amount'      => 0.00,
            'credit_amount'     => $revenue->amount,
            'currency'          => $revenue->currency,
            'date'              => $revenue->date,
            'description'       => "سند قبض رقم {$revenue->receipt_no} من {$revenue->payer_name} - {$revenue->description}",
            'created_by'        => $revenue->created_by,
        ]);
    }
}
