<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountJournalEntry;
use App\Models\Department;
use App\Models\ManualExpense;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExpenseController extends Controller
{
    /**
     * Get list of manual expenses with filtering and summary metrics.
     */
    public function index(Request $request): JsonResponse
    {
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

        $perPage = $request->input('per_page', 20);
        $expenses = $query->paginate($perPage);

        // Calculate summary statistics
        $totalAmount = (float) ManualExpense::where('status', 'posted')->sum('amount');
        $totalCount = ManualExpense::where('status', 'posted')->count();
        
        $byCategory = ManualExpense::where('manual_expenses.status', 'posted')
            ->join('accounts', 'manual_expenses.expense_account_id', '=', 'accounts.id')
            ->select('accounts.name_ar as category_name', 'accounts.code as category_code', DB::raw('SUM(manual_expenses.amount) as total'))
            ->groupBy('accounts.name_ar', 'accounts.code')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $expenses,
            'summary' => [
                'total_amount' => $totalAmount,
                'total_count'  => $totalCount,
                'by_category'  => $byCategory,
            ],
        ]);
    }

    /**
     * Auto-generate the next voucher number (e.g. PV-2026-0001).
     */
    public function suggestVoucherNo(): JsonResponse
    {
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
        $validated = $request->validate([
            'voucher_no'         => ['nullable', 'string', 'max:50', 'unique:manual_expenses,voucher_no'],
            'date'               => ['required', 'date'],
            'expense_account_id' => ['required', 'exists:accounts,id'],
            'payment_account_id' => ['required', 'exists:accounts,id'],
            'amount'             => ['required', 'numeric', 'gt:0'],
            'currency'           => ['nullable', 'string', 'max:10'],
            'department_id'      => ['nullable', 'exists:departments,id'],
            'department_name'    => ['nullable', 'string', 'max:255'],
            'description'        => ['required', 'string'],
            'status'             => ['nullable', 'in:posted,draft,cancelled'],
            'attachment'         => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'], // 10MB max
        ]);

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
            $file = $request->file('attachment');
            $attachmentName = $file->getClientOriginalName();
            $attachmentPath = $file->store('expense_vouchers', 'public');
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

        return response()->json([
            'success' => true,
            'data'    => $expense,
        ]);
    }

    /**
     * Update an expense voucher.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $expense = ManualExpense::findOrFail($id);
        $oldData = $expense->toArray();

        $validated = $request->validate([
            'date'               => ['required', 'date'],
            'expense_account_id' => ['required', 'exists:accounts,id'],
            'payment_account_id' => ['required', 'exists:accounts,id'],
            'amount'             => ['required', 'numeric', 'gt:0'],
            'currency'           => ['nullable', 'string', 'max:10'],
            'department_id'      => ['nullable', 'exists:departments,id'],
            'department_name'    => ['nullable', 'string', 'max:255'],
            'description'        => ['required', 'string'],
            'status'             => ['required', 'in:posted,draft,cancelled'],
            'attachment'         => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);

        $user = $request->user();

        // Handle attachment file upload if new file provided
        if ($request->hasFile('attachment')) {
            // Remove old file if exists
            if ($expense->attachment_path && Storage::disk('public')->exists($expense->attachment_path)) {
                Storage::disk('public')->delete($expense->attachment_path);
            }
            $file = $request->file('attachment');
            $expense->attachment_name = $file->getClientOriginalName();
            $expense->attachment_path = $file->store('expense_vouchers', 'public');
        }

        // Lookup department name if department_id provided
        $departmentName = $validated['department_name'] ?? null;
        if (!empty($validated['department_id'])) {
            $dept = Department::find($validated['department_id']);
            if ($dept) {
                $departmentName = $dept->name_ar ?? $dept->name;
            }
        }

        DB::transaction(function () use ($expense, $validated, $departmentName, $user) {
            $expense->update([
                'date'               => $validated['date'],
                'expense_account_id' => $validated['expense_account_id'],
                'payment_account_id' => $validated['payment_account_id'],
                'amount'             => $validated['amount'],
                'currency'           => $validated['currency'] ?? 'USD',
                'department_id'      => $validated['department_id'] ?? null,
                'department_name'    => $departmentName,
                'description'        => $validated['description'],
                'status'             => $validated['status'],
                'updated_by'         => $user?->id,
            ]);

            // Re-sync journal entries
            AccountJournalEntry::where('manual_expense_id', $expense->id)->delete();
            if ($expense->status === 'posted') {
                $this->createJournalEntries($expense);
            }
        });

        $expense->load(['expenseAccount', 'paymentAccount', 'department', 'creator', 'updater', 'journalEntries.account']);

        AuditLogService::logUpdate($expense, $oldData, $expense->fresh()->toArray(), "عدّل بيانات سند الصرف ({$expense->voucher_no})");

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث سند الصرف وإعادة ترحيل القيد المحاسبي',
            'data'    => $expense,
        ]);
    }

    /**
     * Delete/Cancel expense voucher.
     */
    public function destroy(int $id): JsonResponse
    {
        $expense = ManualExpense::findOrFail($id);
        $voucherNo = $expense->voucher_no;

        DB::transaction(function () use ($expense) {
            AccountJournalEntry::where('manual_expense_id', $expense->id)->delete();
            
            if ($expense->attachment_path && Storage::disk('public')->exists($expense->attachment_path)) {
                Storage::disk('public')->delete($expense->attachment_path);
            }
            
            $expense->delete();
        });

        AuditLogService::logDelete($expense, "حذف سند الصرف ({$voucherNo}) وإلغاء القيد المحاسبي المرتبط به");

        return response()->json([
            'success' => true,
            'message' => 'تم حذف سند الصرف وإلغاء القيد المحاسبي بنجاح',
        ]);
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
