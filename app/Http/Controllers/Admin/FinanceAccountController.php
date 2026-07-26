<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ProtectedFinanceAccountException;
use App\Http\Controllers\Controller;
use App\Models\FinanceAccount;
use App\Models\FinanceAccountTransaction;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceAccountController extends Controller
{
    // GET /finance/accounts
    public function index(Request $request): JsonResponse
    {
        $query = FinanceAccount::orderBy('name');

        if ($request->has('active')) {
            $query->where('is_active', (bool) $request->active);
        }

        $accounts = $query->get()->map(fn ($a) => $this->formatAccount($a));

        return response()->json(['success' => true, 'data' => $accounts]);
    }

    // GET /finance/accounts/{account}
    public function show(FinanceAccount $account): JsonResponse
    {
        $account->loadCount('transactions');

        $ledgerBalance = $this->calculateLedgerBalance($account);

        return response()->json([
            'success' => true,
            'data'    => array_merge($this->formatAccount($account), [
                'transaction_count' => $account->transactions_count,
                'ledger_balance'    => $ledgerBalance,
            ]),
        ]);
    }

    // POST /finance/accounts
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'             => 'required|string|max:191',
            'type'             => 'required|in:bank,cash,paypal,stripe,ing,other,payment_gateway,wallet',
            'currency'         => 'required|in:EUR,USD,SAR,TRY,GBP',
            'opening_balance'  => 'nullable|numeric|min:0',
            'notes'            => 'nullable|string|max:1000',
            'bank_name'        => 'nullable|string|max:191',
            'account_holder'   => 'nullable|string|max:191',
            'iban'             => 'nullable|string|max:34',
            'account_number'   => 'nullable|string|max:191',
        ]);

        $data['current_balance'] = $data['opening_balance'] ?? 0;
        $data['created_by']      = $request->user()?->id;

        $account = FinanceAccount::create($data);

        AuditLogService::log('finance_account.created', 'FinanceAccount', $account->id, $account->name, null, $account->toArray());

        return response()->json(['success' => true, 'data' => $this->formatAccount($account)], 201);
    }

    // PATCH /finance/accounts/{account}
    public function update(Request $request, FinanceAccount $account): JsonResponse
    {
        $data = $request->validate([
            'name'           => 'sometimes|string|max:191',
            'type'           => 'sometimes|in:bank,cash,paypal,stripe,ing,other,payment_gateway,wallet',
            'currency'       => 'sometimes|in:EUR,USD,SAR,TRY,GBP',
            'notes'          => 'nullable|string|max:1000',
            'is_active'      => 'sometimes|boolean',
            'bank_name'      => 'nullable|string|max:191',
            'account_holder' => 'nullable|string|max:191',
            'iban'           => 'nullable|string|max:34',
            'account_number' => 'nullable|string|max:191',
        ]);

        $old = $account->toArray();
        $data['updated_by'] = $request->user()?->id;

        try {
            $account->update($data);
        } catch (ProtectedFinanceAccountException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        AuditLogService::log('finance_account.updated', 'FinanceAccount', $account->id, $account->name, $old, $account->fresh()->toArray());

        return response()->json(['success' => true, 'data' => $this->formatAccount($account->fresh())]);
    }

    // DELETE /finance/accounts/{account}
    public function destroy(Request $request, FinanceAccount $account): JsonResponse
    {
        if (! $account->is_deletable) {
            return response()->json([
                'success' => false,
                'message' => 'This account is linked to a payment gateway and cannot be deleted.',
            ], 422);
        }

        $old = $account->toArray();
        $account->update(['updated_by' => $request->user()?->id]);

        try {
            $account->delete();
        } catch (ProtectedFinanceAccountException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        AuditLogService::log('finance_account.deleted', 'FinanceAccount', $account->id, $account->name, $old, null);

        return response()->json(['success' => true, 'message' => 'Account deleted.']);
    }

    // POST /finance/accounts/{account}/recalculate
    public function recalculate(Request $request, FinanceAccount $account): JsonResponse
    {
        $ledgerBalance = DB::transaction(function () use ($account) {
            $balance = $this->calculateLedgerBalance($account);
            $account->update([
                'current_balance' => $balance,
                'updated_by'      => request()->user()?->id,
            ]);
            return $balance;
        });

        AuditLogService::log('finance_account.recalculated', 'FinanceAccount', $account->id, $account->name);

        return response()->json([
            'success' => true,
            'data'    => ['new_balance' => $ledgerBalance, 'account' => $this->formatAccount($account->fresh())],
        ]);
    }

    // GET /finance/accounts/{account}/transactions
    public function transactions(Request $request, FinanceAccount $account): JsonResponse
    {
        $txns = $account->transactions()
            ->when($request->from, fn ($q) => $q->whereDate('transaction_date', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('transaction_date', '<=', $request->to))
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(30);

        return response()->json(['success' => true, 'data' => $txns]);
    }

    // POST /finance/accounts/{account}/transactions
    public function addTransaction(Request $request, FinanceAccount $account): JsonResponse
    {
        $data = $request->validate([
            'type'                   => 'required|in:revenue,expense,transfer,transfer_in,balance_adjustment',
            'category'               => 'nullable|string|max:100',
            'amount'                 => 'required|numeric|min:0.01',
            'currency'               => 'nullable|string|max:10',
            'status'                 => 'nullable|in:confirmed,pending,cancelled',
            'description'            => 'nullable|string|max:1000',
            'payment_method'         => 'nullable|string|max:100',
            'transaction_date'       => 'required|date',
            'external_reference'     => 'nullable|string|max:191',
            'destination_account_id' => 'required_if:type,transfer|nullable|exists:finance_accounts,id|different:id',
        ]);

        $data['finance_account_id'] = $account->id;
        $data['currency']           = $data['currency'] ?? $account->currency;
        $data['status']             = $data['status'] ?? 'confirmed';
        $data['created_by']         = $request->user()?->id;

        DB::transaction(function () use ($account, $data) {
            $isConfirmed = ($data['status'] ?? 'confirmed') === 'confirmed';
            $type        = $data['type'];

            FinanceAccountTransaction::create($data);

            if ($isConfirmed) {
                if (in_array($type, ['revenue', 'transfer_in', 'balance_adjustment'])) {
                    $account->increment('current_balance', $data['amount']);
                } elseif (in_array($type, ['expense', 'transfer'])) {
                    $account->decrement('current_balance', $data['amount']);
                }

                // For transfers, create mirrored transfer_in on destination account
                if ($type === 'transfer' && ! empty($data['destination_account_id'])) {
                    $dest = FinanceAccount::lockForUpdate()->find($data['destination_account_id']);
                    if ($dest) {
                        FinanceAccountTransaction::create([
                            'finance_account_id'     => $dest->id,
                            'type'                   => 'transfer_in',
                            'category'               => $data['category'] ?? 'transfer',
                            'amount'                 => $data['amount'],
                            'currency'               => $data['currency'],
                            'status'                 => $data['status'],
                            'description'            => ($data['description'] ?? '') . " [Transfer from: {$account->name}]",
                            'payment_method'         => $data['payment_method'] ?? null,
                            'transaction_date'       => $data['transaction_date'],
                            'destination_account_id' => $account->id,
                            'external_reference'     => $data['external_reference'] ?? null,
                            'created_by'             => $data['created_by'],
                        ]);
                        $dest->increment('current_balance', $data['amount']);
                    }
                }
            }
        });

        return response()->json(['success' => true, 'data' => $this->formatAccount($account->fresh())], 201);
    }

    private function calculateLedgerBalance(FinanceAccount $account): float
    {
        $income = (float) $account->transactions()
            ->where('status', 'confirmed')
            ->whereIn('type', ['revenue', 'transfer_in', 'balance_adjustment'])
            ->sum('amount');

        $outflow = (float) $account->transactions()
            ->where('status', 'confirmed')
            ->whereIn('type', ['expense', 'transfer'])
            ->sum('amount');

        return (float) $account->opening_balance + $income - $outflow;
    }

    private function formatAccount(FinanceAccount $a): array
    {
        return [
            'id'               => $a->id,
            'code'             => $a->code,
            'name'             => $a->name,
            'type'             => $a->type,
            'provider'         => $a->provider,
            'payment_method'   => $a->payment_method,
            'currency'         => $a->currency,
            'opening_balance'  => (float) $a->opening_balance,
            'current_balance'  => (float) $a->current_balance,
            'notes'            => $a->notes,
            'is_active'        => $a->is_active,
            'is_system'        => $a->is_system,
            'is_deletable'     => $a->is_deletable,
            'bank_name'        => $a->bank_name,
            'account_holder'   => $a->account_holder,
            'iban'             => $a->iban,
            'account_number'   => $a->account_number,
            'created_at'       => $a->created_at?->toDateString(),
        ];
    }
}
