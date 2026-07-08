<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinanceAccount;
use App\Models\FinanceAccountTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceAccountController extends Controller
{
    // GET /finance/accounts
    public function index(): JsonResponse
    {
        $accounts = FinanceAccount::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($a) => $this->formatAccount($a));

        return response()->json(['success' => true, 'data' => $accounts]);
    }

    // POST /finance/accounts
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'            => 'required|string|max:191',
            'type'            => 'required|in:bank,cash,paypal,stripe,ing,other',
            'currency'        => 'required|in:EUR,USD,SAR,TRY',
            'opening_balance' => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string|max:1000',
        ]);

        $data['current_balance'] = $data['opening_balance'] ?? 0;
        $data['created_by']      = $request->user()?->id;

        $account = FinanceAccount::create($data);

        return response()->json(['success' => true, 'data' => $this->formatAccount($account)], 201);
    }

    // PATCH /finance/accounts/{account}
    public function update(Request $request, FinanceAccount $account): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'sometimes|string|max:191',
            'type'     => 'sometimes|in:bank,cash,paypal,stripe,ing,other',
            'currency' => 'sometimes|in:EUR,USD,SAR,TRY',
            'notes'    => 'nullable|string|max:1000',
        ]);

        $data['updated_by'] = $request->user()?->id;
        $account->update($data);

        return response()->json(['success' => true, 'data' => $this->formatAccount($account->fresh())]);
    }

    // GET /finance/accounts/{account}/transactions
    public function transactions(Request $request, FinanceAccount $account): JsonResponse
    {
        $txns = $account->transactions()
            ->when($request->from, fn ($q) => $q->whereDate('transaction_date', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('transaction_date', '<=', $request->to))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(30);

        return response()->json(['success' => true, 'data' => $txns]);
    }

    // POST /finance/accounts/{account}/transactions
    public function addTransaction(Request $request, FinanceAccount $account): JsonResponse
    {
        $data = $request->validate([
            'type'             => 'required|in:revenue,expense,transfer,balance_adjustment',
            'category'         => 'nullable|string|max:100',
            'amount'           => 'required|numeric|min:0.01',
            'currency'         => 'nullable|string|max:10',
            'status'           => 'nullable|in:confirmed,pending,cancelled',
            'description'      => 'nullable|string|max:1000',
            'payment_method'   => 'nullable|string|max:100',
            'transaction_date' => 'required|date',
        ]);

        $data['finance_account_id'] = $account->id;
        $data['currency']           = $data['currency'] ?? $account->currency;
        $data['status']             = $data['status'] ?? 'confirmed';
        $data['created_by']         = $request->user()?->id;

        DB::transaction(function () use ($account, $data) {
            FinanceAccountTransaction::create($data);

            // Update account current_balance only for confirmed transactions
            if (($data['status'] ?? 'confirmed') === 'confirmed') {
                $delta = in_array($data['type'], ['revenue', 'balance_adjustment'])
                    ? $data['amount']
                    : -$data['amount'];
                $account->increment('current_balance', $delta);
            }
        });

        return response()->json(['success' => true, 'data' => $this->formatAccount($account->fresh())], 201);
    }

    private function formatAccount(FinanceAccount $a): array
    {
        return [
            'id'               => $a->id,
            'name'             => $a->name,
            'type'             => $a->type,
            'currency'         => $a->currency,
            'opening_balance'  => (float) $a->opening_balance,
            'current_balance'  => (float) $a->current_balance,
            'notes'            => $a->notes,
            'is_active'        => $a->is_active,
            'created_at'       => $a->created_at?->toDateString(),
        ];
    }
}
