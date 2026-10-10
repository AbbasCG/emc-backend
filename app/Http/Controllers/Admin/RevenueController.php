<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountJournalEntry;
use App\Models\ManualRevenue;
use App\Models\Course;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RevenueController extends Controller
{
    /**
     * Get list of manual revenues with filtering and summary metrics.
     */
    public function index(Request $request): JsonResponse
    {
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

        $perPage = $request->input('per_page', 20);
        $revenues = $query->paginate($perPage);

        // Summary statistics
        $totalAmount = (float) ManualRevenue::where('status', 'posted')->sum('amount');
        $totalCount = ManualRevenue::where('status', 'posted')->count();

        $byCategory = ManualRevenue::where('manual_revenues.status', 'posted')
            ->join('accounts', 'manual_revenues.revenue_account_id', '=', 'accounts.id')
            ->select('accounts.name_ar as category_name', 'accounts.code as category_code', DB::raw('SUM(manual_revenues.amount) as total'))
            ->groupBy('accounts.name_ar', 'accounts.code')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $revenues,
            'summary' => [
                'total_amount' => $totalAmount,
                'total_count'  => $totalCount,
                'by_category'  => $byCategory,
            ],
        ]);
    }

    /**
     * Auto-generate the next receipt voucher number (e.g. RV-2026-0001).
     */
    public function suggestReceiptNo(): JsonResponse
    {
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
        $validated = $request->validate([
            'receipt_no font'    => ['nullable', 'string', 'max:50'],
            'receipt_no'         => ['nullable', 'string', 'max:50', 'unique:manual_revenues,receipt_no'],
            'date'               => ['required', 'date'],
            'revenue_account_id' => ['required', 'exists:accounts,id'],
            'deposit_account_id' => ['required', 'exists:accounts,id'],
            'amount'             => ['required', 'numeric', 'gt:0'],
            'currency'           => ['nullable', 'string', 'max:10'],
            'payer_name'         => ['required', 'string', 'max:255'],
            'program_id'         => ['nullable', 'integer'],
            'program_name'       => ['nullable', 'string', 'max:255'],
            'description'        => ['required', 'string'],
            'status'             => ['nullable', 'in:posted,draft,cancelled'],
            'attachment'         => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'], // 10MB max
        ]);

        $user = $request->user();

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
            $file = $request->file('attachment');
            $attachmentName = $file->getClientOriginalName();
            $attachmentPath = $file->store('receipt_vouchers', 'public');
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
        $currency = $validated['currency'] ?? 'USD';

        $revenue = DB::transaction(function () use ($validated, $receiptNo, $attachmentPath, $attachmentName, $programName, $revenueStatus, $currency, $user) {
            $manualRevenue = ManualRevenue::create([
                'receipt_no'         => $receiptNo,
                'date'               => $validated['date'],
                'revenue_account_id' => $validated['revenue_account_id'],
                'deposit_account_id' => $validated['deposit_account_id'],
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

        return response()->json([
            'success' => true,
            'data'    => $revenue,
        ]);
    }

    /**
     * Update a receipt voucher.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $revenue = ManualRevenue::findOrFail($id);
        $oldData = $revenue->toArray();

        $validated = $request->validate([
            'date'               => ['required', 'date'],
            'revenue_account_id' => ['required', 'exists:accounts,id'],
            'deposit_account_id' => ['required', 'exists:accounts,id'],
            'amount'             => ['required', 'numeric', 'gt:0'],
            'currency'           => ['nullable', 'string', 'max:10'],
            'payer_name'         => ['required', 'string', 'max:255'],
            'program_id'         => ['nullable', 'integer'],
            'program_name'       => ['nullable', 'string', 'max:255'],
            'description'        => ['required', 'string'],
            'status'             => ['required', 'in:posted,draft,cancelled'],
            'attachment'         => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);

        $user = $request->user();

        // Handle attachment file upload if new file provided
        if ($request->hasFile('attachment')) {
            if ($revenue->attachment_path && Storage::disk('public')->exists($revenue->attachment_path)) {
                Storage::disk('public')->delete($revenue->attachment_path);
            }
            $file = $request->file('attachment');
            $revenue->attachment_name = $file->getClientOriginalName();
            $revenue->attachment_path = $file->store('receipt_vouchers', 'public');
        }

        // Lookup program name if program_id provided
        $programName = $validated['program_name'] ?? null;
        if (!empty($validated['program_id'])) {
            $prog = Course::find($validated['program_id']);
            if ($prog) {
                $programName = $prog->title_ar ?? $prog->title ?? $prog->name;
            }
        }

        DB::transaction(function () use ($revenue, $validated, $programName, $user) {
            $revenue->update([
                'date'               => $validated['date'],
                'revenue_account_id' => $validated['revenue_account_id'],
                'deposit_account_id' => $validated['deposit_account_id'],
                'amount'             => $validated['amount'],
                'currency'           => $validated['currency'] ?? 'USD',
                'payer_name'         => $validated['payer_name'],
                'program_id'         => $validated['program_id'] ?? null,
                'program_name'       => $programName,
                'description'        => $validated['description'],
                'status'             => $validated['status'],
                'updated_by'         => $user?->id,
            ]);

            // Re-sync journal entries
            AccountJournalEntry::where('manual_revenue_id', $revenue->id)->delete();
            if ($revenue->status === 'posted') {
                $this->createJournalEntries($revenue);
            }
        });

        $revenue->load(['revenueAccount', 'depositAccount', 'program', 'creator', 'updater', 'journalEntries.account']);

        AuditLogService::logUpdate($revenue, $oldData, $revenue->fresh()->toArray(), "عدّل بيانات سند القبض ({$revenue->receipt_no})");

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث سند القبض وإعادة ترحيل القيد المحاسبي',
            'data'    => $revenue,
        ]);
    }

    /**
     * Delete/Cancel receipt voucher.
     */
    public function destroy(int $id): JsonResponse
    {
        $revenue = ManualRevenue::findOrFail($id);
        $receiptNo = $revenue->receipt_no;

        DB::transaction(function () use ($revenue) {
            AccountJournalEntry::where('manual_revenue_id', $revenue->id)->delete();

            if ($revenue->attachment_path && Storage::disk('public')->exists($revenue->attachment_path)) {
                Storage::disk('public')->delete($revenue->attachment_path);
            }

            $revenue->delete();
        });

        AuditLogService::logDelete($revenue, "حذف سند القبض ({$receiptNo}) وإلغاء القيد المحاسبي المرتبط به");

        return response()->json([
            'success' => true,
            'message' => 'تم حذف سند القبض وإلغاء القيد المحاسبي بنجاح',
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
