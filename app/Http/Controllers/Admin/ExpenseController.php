<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountJournalEntry;
use App\Models\Department;
use App\Models\ManualExpense;
use App\Services\AuditLogService;
use App\Services\Finance\VoucherAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ExpenseController extends Controller
{
    private const ATTACHMENT_DIR = 'expense_vouchers';

    /** Same set FinanceAccountController accepts. */
    private const CURRENCIES = ['EUR', 'USD', 'YER', 'SAR', 'TRY', 'GBP'];

    public function __construct(private readonly VoucherAttachmentService $attachments) {}

    /**
     * Get list of manual expenses with filtering and summary metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ManualExpense::class);

        $query = ManualExpense::with([
            'expenseAccount',
            'paymentAccount',
            'department',
            'creator',
            'journalEntries.account',
        ]);

        // Search filter
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('voucher_no', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('department_name', 'like', "%{$search}%");
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

        // Expense Account filter
        if ($request->filled('expense_account_id')) {
            $query->where('expense_account_id', $request->input('expense_account_id'));
        }

        // Payment Account filter
        if ($request->filled('payment_account_id')) {
            $query->where('payment_account_id', $request->input('payment_account_id'));
        }

        // Department filter
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->input('department_id'));
        }

        $query->orderBy('date', 'desc')->orderBy('id', 'desc');

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $expenses = $query->paginate($perPage);

        // Calculate summary statistics
        $totalAmount = (float) ManualExpense::where('status', 'posted')->sum('amount');
        $totalCount = ManualExpense::where('status', 'posted')->count();

        $byCategory = ManualExpense::where('manual_expenses.status', 'posted')
            ->join('accounts', 'manual_expenses.expense_account_id', '=', 'accounts.id')
            ->select('accounts.name_ar as category_name', 'accounts.code as category_code', 'manual_expenses.currency', DB::raw('SUM(manual_expenses.amount) as total'))
            ->groupBy('accounts.name_ar', 'accounts.code', 'manual_expenses.currency')
            ->toBase()
            ->get();

        // Amounts in different currencies are never added together.
        $byCurrency = ManualExpense::where('status', 'posted')
            ->select('currency', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('currency')
            ->orderBy('currency')
            ->toBase()
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $expenses,
            'summary' => [
                'total_amount' => $totalAmount,
                'total_count'  => $totalCount,
                'by_category'  => $byCategory,
                'by_currency'  => $byCurrency,
            ],
        ]);
    }

    /**
     * Auto-generate the next voucher number (e.g. PV-2026-0001).
     */
    public function suggestVoucherNo(): JsonResponse
    {
        $this->authorize('create', ManualExpense::class);

        $year = date('Y');
        $prefix = "PV-{$year}-";

        $lastVoucher = ManualExpense::where('voucher_no', 'like', "{$prefix}%")
            ->orderBy('id', 'desc')
            ->first();

        if ($lastVoucher) {
            $seqStr = str_replace($prefix, '', $lastVoucher->voucher_no);
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
     * Store a new manual expense voucher & generate balanced journal entries.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ManualExpense::class);

        $validated = $request->validate([
            'voucher_no'         => ['nullable', 'string', 'max:50', 'unique:manual_expenses,voucher_no'],
            'date'               => ['required', 'date'],
            'expense_account_id' => ['required', 'exists:accounts,id'],
            'payment_account_id' => ['required', 'exists:accounts,id', 'different:expense_account_id'],
            'amount'             => ['required', 'numeric', 'gt:0'],
            'currency'           => ['nullable', 'string', 'in:'.implode(',', self::CURRENCIES)],
            'department_id'      => ['nullable', 'exists:departments,id'],
            'department_name'    => ['nullable', 'string', 'max:255'],
            'description'        => ['required', 'string'],
            'status'             => ['nullable', 'in:posted,draft,cancelled'],
            'attachment'         => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'], // 10MB max
        ]);

        $this->assertPostableAccounts($validated);

        $user = $request->user();

        // Autogenerate voucher_no if not provided
        if (empty($validated['voucher_no'])) {
            $year = date('Y', strtotime($validated['date']));
            $prefix = "PV-{$year}-";
            $lastVoucher = ManualExpense::where('voucher_no', 'like', "{$prefix}%")
                ->orderBy('id', 'desc')
                ->first();
            $seq = $lastVoucher ? ((int) str_replace($prefix, '', $lastVoucher->voucher_no) + 1) : 1;
            $voucherNo = $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        } else {
            $voucherNo = $validated['voucher_no'];
        }

        // Handle attachment file upload
        $attachmentPath = null;
        $attachmentName = null;
        if ($request->hasFile('attachment')) {
            [$attachmentPath, $attachmentName] = $this->attachments->store($request->file('attachment'), self::ATTACHMENT_DIR);
        }

        // Lookup department name if department_id provided
        $departmentName = $validated['department_name'] ?? null;
        if (!empty($validated['department_id'])) {
            $dept = Department::find($validated['department_id']);
            if ($dept) {
                $departmentName = $dept->name_ar ?? $dept->name;
            }
        }

        $expenseStatus = $validated['status'] ?? 'posted';
        $currency = $validated['currency'] ?? 'USD';

        try {
            $expense = DB::transaction(function () use ($validated, $voucherNo, $attachmentPath, $attachmentName, $departmentName, $expenseStatus, $currency, $user) {
                $manualExpense = ManualExpense::create([
                    'voucher_no'         => $voucherNo,
                    'date'               => $validated['date'],
                    'expense_account_id' => $validated['expense_account_id'],
                    'payment_account_id' => $validated['payment_account_id'],
                    'amount'             => $validated['amount'],
                    'currency'           => $currency,
                    'department_id'      => $validated['department_id'] ?? null,
                    'department_name'    => $departmentName,
                    'description'        => $validated['description'],
                    'attachment_path'    => $attachmentPath,
                    'attachment_name'    => $attachmentName,
                    'status'             => $expenseStatus,
                    'created_by'         => $user?->id,
                ]);

                // If posted, generate the double-entry journal entries
                if ($expenseStatus === 'posted') {
                    $this->createJournalEntries($manualExpense);
                }

                return $manualExpense;
            });
        } catch (\Throwable $e) {
            $this->attachments->delete($attachmentPath, self::ATTACHMENT_DIR);
            throw $e;
        }

        $expense->load(['expenseAccount', 'paymentAccount', 'department', 'creator', 'journalEntries.account']);

        AuditLogService::logCreate($expense, "سجّل سند صرف جديد برقم: {$expense->voucher_no} بمبلغ {$expense->amount} {$expense->currency}");

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل وسند الصرف بنجاح وترحيل القيد المحاسبي',
            'data'    => $expense,
        ], 201);
    }

    /**
     * Show single expense details.
     */
    public function show(int $id): JsonResponse
    {
        $expense = ManualExpense::with([
            'expenseAccount',
            'paymentAccount',
            'department',
            'creator',
            'updater',
            'journalEntries.account',
        ])->findOrFail($id);

        $this->authorize('view', $expense);

        return response()->json([
            'success' => true,
            'data'    => $expense,
        ]);
    }

    /**
     * Update a draft expense voucher. Posting a draft generates its journal
     * entries; a posted voucher is immutable and is refused with a 422.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $expense = ManualExpense::findOrFail($id);
        $this->authorize('update', $expense);
        $this->assertNotPosted($expense);

        $oldData = $expense->toArray();

        $validated = $request->validate([
            'date'               => ['required', 'date'],
            'expense_account_id' => ['required', 'exists:accounts,id'],
            'payment_account_id' => ['required', 'exists:accounts,id', 'different:expense_account_id'],
            'amount'             => ['required', 'numeric', 'gt:0'],
            'currency'           => ['nullable', 'string', 'in:'.implode(',', self::CURRENCIES)],
            'department_id'      => ['nullable', 'exists:departments,id'],
            'department_name'    => ['nullable', 'string', 'max:255'],
            'description'        => ['required', 'string'],
            'status'             => ['required', 'in:posted,draft,cancelled'],
            'attachment'         => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);

        $this->assertPostableAccounts($validated);

        $user = $request->user();

        // Store a replacement attachment; the old file is removed only once the update commits.
        $oldAttachment = null;
        $newAttachment = null;
        if ($request->hasFile('attachment')) {
            $oldAttachment = $expense->attachment_path;
            [$newAttachment, $expense->attachment_name] = $this->attachments->store($request->file('attachment'), self::ATTACHMENT_DIR);
            $expense->attachment_path = $newAttachment;
        }

        // Lookup department name if department_id provided
        $departmentName = $validated['department_name'] ?? null;
        if (!empty($validated['department_id'])) {
            $dept = Department::find($validated['department_id']);
            if ($dept) {
                $departmentName = $dept->name_ar ?? $dept->name;
            }
        }

        try {
            DB::transaction(function () use ($expense, $validated, $departmentName, $user) {
                $expense->update([
                    'date'               => $validated['date'],
                    'expense_account_id' => $validated['expense_account_id'],
                    'payment_account_id' => $validated['payment_account_id'],
                    'amount'             => $validated['amount'],
                    'currency'           => $validated['currency'] ?? $expense->currency ?? 'USD',
                    'department_id'      => $validated['department_id'] ?? null,
                    'department_name'    => $departmentName,
                    'description'        => $validated['description'],
                    'status'             => $validated['status'],
                    'updated_by'         => $user?->id,
                ]);

                // A draft has no journal entries; posting it creates them once.
                if ($expense->status === 'posted') {
                    $this->createJournalEntries($expense);
                }
            });
        } catch (\Throwable $e) {
            $this->attachments->delete($newAttachment, self::ATTACHMENT_DIR);
            throw $e;
        }

        if ($oldAttachment) {
            $this->attachments->delete($oldAttachment, self::ATTACHMENT_DIR);
        }

        $expense->load(['expenseAccount', 'paymentAccount', 'department', 'creator', 'updater', 'journalEntries.account']);

        AuditLogService::logUpdate($expense, $oldData, $expense->fresh()->toArray(), "عدّل بيانات سند الصرف ({$expense->voucher_no})");

        return response()->json([
            'success' => true,
            'message' => $expense->status === 'posted' ? 'تم تحديث سند الصرف وترحيل القيد المحاسبي' : 'تم تحديث سند الصرف',
            'data'    => $expense,
        ]);
    }

    /**
     * Delete a draft or cancelled expense voucher. Posted vouchers are never
     * hard-deleted — their journal entries are part of the ledger.
     */
    public function destroy(int $id): JsonResponse
    {
        $expense = ManualExpense::findOrFail($id);
        $this->authorize('delete', $expense);
        $this->assertNotPosted($expense);

        $voucherNo = $expense->voucher_no;
        $attachmentPath = $expense->attachment_path;

        $expense->delete();
        $this->attachments->delete($attachmentPath, self::ATTACHMENT_DIR);

        AuditLogService::logDelete($expense, "حذف مسودة سند الصرف ({$voucherNo})");

        return response()->json([
            'success' => true,
            'message' => 'تم حذف سند الصرف بنجاح',
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
        $expense = ManualExpense::findOrFail($id);
        $this->authorize('downloadAttachment', $expense);

        return $this->attachments->respond($expense, self::ATTACHMENT_DIR, "سند الصرف {$expense->voucher_no}", $inline);
    }

    private function assertNotPosted(ManualExpense $expense): void
    {
        if ($expense->isPosted()) {
            throw ValidationException::withMessages([
                'status' => 'سند الصرف مُرحّل محاسبياً ولا يمكن تعديله أو حذفه. لتصحيحه سجّل سنداً عكسياً.',
            ]);
        }
    }

    /**
     * Journal lines may only post to leaf (selectable) accounts, and the
     * expense side must be a debit-nature account.
     */
    private function assertPostableAccounts(array $validated): void
    {
        $accounts = Account::whereIn('id', [$validated['expense_account_id'], $validated['payment_account_id']])
            ->get()->keyBy('id');
        $expenseAccount = $accounts->get((int) $validated['expense_account_id']);
        $paymentAccount = $accounts->get((int) $validated['payment_account_id']);

        $errors = [];
        if (! $expenseAccount?->is_selectable || $expenseAccount->type !== 'debit') {
            $errors['expense_account_id'] = 'حساب المصروف يجب أن يكون حساباً فرعياً قابلاً للترحيل ومن طبيعة مدينة.';
        }
        if (! $paymentAccount?->is_selectable) {
            $errors['payment_account_id'] = 'حساب الدفع يجب أن يكون حساباً فرعياً قابلاً للترحيل.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Helper to create double-entry journal entries for an expense voucher.
     */
    private function createJournalEntries(ManualExpense $expense): void
    {
        // 1. Debit Entry (من حـ/ المصروفات - مدين)
        AccountJournalEntry::create([
            'voucher_no'        => $expense->voucher_no,
            'transaction_type'  => 'expense',
            'manual_expense_id' => $expense->id,
            'account_id'        => $expense->expense_account_id,
            'entry_type'        => 'debit',
            'debit_amount'      => $expense->amount,
            'credit_amount'     => 0.00,
            'currency'          => $expense->currency,
            'date'              => $expense->date,
            'description'       => "سند صرف رقم {$expense->voucher_no} - {$expense->description}",
            'created_by'        => $expense->created_by,
        ]);

        // 2. Credit Entry (إلى حـ/ حساب الدفع - دائن)
        AccountJournalEntry::create([
            'voucher_no'        => $expense->voucher_no,
            'transaction_type'  => 'expense',
            'manual_expense_id' => $expense->id,
            'account_id'        => $expense->payment_account_id,
            'entry_type'        => 'credit',
            'debit_amount'      => 0.00,
            'credit_amount'     => $expense->amount,
            'currency'          => $expense->currency,
            'date'              => $expense->date,
            'description'       => "سند صرف رقم {$expense->voucher_no} - {$expense->description}",
            'created_by'        => $expense->created_by,
        ]);
    }
}
