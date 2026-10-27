<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ProtectedChartOfAccountException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChartOfAccountsController extends Controller
{
    /**
     * Get full hierarchical tree structure of accounts.
     */
    public function tree(): JsonResponse
    {
        $roots = Account::roots()->with('allChildren')->get();

        return response()->json([
            'success' => true,
            'data'    => $roots,
        ]);
    }

    /**
     * Get flat list or filtered accounts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Account::with('parent');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('name_ar', 'like', "%{$search}%")
                  ->orWhere('name_en', 'like', "%{$search}%");
            });
        }

        if ($request->filled('selectable_only')) {
            $query->selectable();
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $accounts = $query->orderBy('code', 'asc')->get();

        return response()->json([
            'success' => true,
            'data'    => $accounts,
        ]);
    }

    /**
     * Create a new account with auto-type inheritance from parent.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'          => ['required', 'string', 'max:20', 'unique:accounts,code'],
            'name_ar'       => ['required', 'string', 'max:255'],
            'name_en'       => ['nullable', 'string', 'max:255'],
            'type'          => ['nullable', 'in:debit,credit'],
            'parent_id'     => ['nullable', 'exists:accounts,id'],
            'is_selectable' => ['nullable', 'boolean'],
        ]);

        $type = $validated['type'] ?? 'debit';

        // Auto-inherit type from parent if parent_id is specified
        if (!empty($validated['parent_id'])) {
            $parent = Account::find($validated['parent_id']);
            if ($parent) {
                $type = $parent->type;
            }
        }

        // New accounts are always ordinary (never system-protected) — the
        // is_system/is_deletable flags are only ever set by trusted seeders,
        // never accepted from the request payload.
        $account = Account::create([
            'code'          => $validated['code'],
            'name_ar'       => $validated['name_ar'],
            'name_en'       => $validated['name_en'] ?? null,
            'type'          => $type,
            'parent_id'     => $validated['parent_id'] ?? null,
            'is_selectable' => $validated['is_selectable'] ?? true,
            'is_system'     => false,
            'is_deletable'  => true,
            'created_by'    => $request->user()?->id,
        ]);

        AuditLogService::logCreate($account, "أنشأ حساباً محاسبياً جديداً: ({$account->code}) {$account->name_ar}");

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء الحساب بنجاح',
            'data'    => $account->load('parent'),
        ], 201);
    }

    /**
     * Update account details. Code/type are intentionally not editable here
     * at all (not just for system accounts) — changing either after
     * creation would corrupt any transactions already posted against this
     * account's stable code/nature, whether or not it's a "system" account.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $account = Account::findOrFail($id);
        $old = $account->toArray();

        $validated = $request->validate([
            'name_ar'              => ['required', 'string', 'max:255'],
            'name_en'              => ['nullable', 'string', 'max:255'],
            'is_selectable'        => ['required', 'boolean'],
            'default_for_currency' => ['nullable', 'string', 'in:USD,YER,SAR,EUR,TRY,GBP'],
        ]);

        // Enforce uniqueness: clear any other account that currently holds
        // the same default_for_currency before setting it on this one.
        if (!empty($validated['default_for_currency'])) {
            Account::where('default_for_currency', $validated['default_for_currency'])
                   ->where('id', '!=', $account->id)
                   ->update(['default_for_currency' => null]);
        }

        try {
            $account->update([
                'name_ar'              => $validated['name_ar'],
                'name_en'              => $validated['name_en'] ?? null,
                'is_selectable'        => $validated['is_selectable'],
                'default_for_currency' => $validated['default_for_currency'] ?? null,
                'updated_by'           => $request->user()?->id,
            ]);
        } catch (ProtectedChartOfAccountException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code'    => 'finance_account_protected',
            ], 422);
        }

        AuditLogService::logUpdate($account, $old, $account->fresh()->toArray(), "عدّل بيانات الحساب المحاسبي ({$account->code})");

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الحساب بنجاح',
            'data'    => $account,
        ]);
    }

    /**
     * Delete account with safety checks — enforced centrally in
     * Account::booted()'s `deleting` hook (system/non-deletable, children,
     * linked transactions), never trusted to the frontend.
     */
    public function destroy(int $id): JsonResponse
    {
        $account = Account::findOrFail($id);
        $old = $account->toArray();

        try {
            $account->delete();
        } catch (ProtectedChartOfAccountException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code'    => 'finance_account_protected',
            ], 422);
        }

        AuditLogService::logDelete($account, "حذف الحساب المحاسبي ({$old['code']}) {$old['name_ar']}");

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الحساب بنجاح',
        ]);
    }

    /**
     * GET /admin/finance/accounts/defaults
     * Returns the currently configured default account per currency.
     */
    public function defaults(): JsonResponse
    {
        $defaults = Account::whereNotNull('default_for_currency')
                           ->get()
                           ->keyBy('default_for_currency')
                           ->map(fn ($a) => [
                               'id'       => $a->id,
                               'code'     => $a->code,
                               'name_ar'  => $a->name_ar,
                               'currency' => $a->default_for_currency,
                           ]);

        return response()->json(['success' => true, 'data' => $defaults]);
    }

    /**
     * Auto-coding generator for new child accounts under a parent.
     */
    public function suggestCode(Request $request): JsonResponse
    {
        $parentId = $request->query('parent_id');

        if (!$parentId) {
            // Root level suggestion
            $maxRootCode = Account::whereNull('parent_id')->max('code');
            $nextCode = $maxRootCode ? (string) ((int) $maxRootCode + 1) : '1';

            return response()->json([
                'success'       => true,
                'suggested_code'=> $nextCode,
                'type'          => 'debit',
            ]);
        }

        $parent = Account::find($parentId);

        if (!$parent) {
            return response()->json([
                'success' => false,
                'message' => 'الحساب الأب غير موجود.',
            ], 404);
        }

        $lastChild = Account::where('parent_id', $parentId)->orderBy('code', 'desc')->first();

        if ($lastChild && is_numeric($lastChild->code)) {
            $suggestedCode = (string) ((int) $lastChild->code + 1);
        } else {
            // Append 01 to parent code (e.g. 33 -> 3301)
            $suggestedCode = $parent->code . '01';
        }

        return response()->json([
            'success'       => true,
            'suggested_code'=> $suggestedCode,
            'type'          => $parent->type,
            'parent_name'   => $parent->name_ar,
        ]);
    }
}
