<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $account = Account::create([
            'code'          => $validated['code'],
            'name_ar'       => $validated['name_ar'],
            'name_en'       => $validated['name_en'] ?? null,
            'type'          => $type,
            'parent_id'     => $validated['parent_id'] ?? null,
            'is_selectable' => $validated['is_selectable'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء الحساب بنجاح',
            'data'    => $account->load('parent'),
        ], 201);
    }

    /**
     * Update account details.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $account = Account::findOrFail($id);

        $validated = $request->validate([
            'name_ar'       => ['required', 'string', 'max:255'],
            'name_en'       => ['nullable', 'string', 'max:255'],
            'is_selectable' => ['required', 'boolean'],
        ]);

        $account->update([
            'name_ar'       => $validated['name_ar'],
            'name_en'       => $validated['name_en'] ?? null,
            'is_selectable' => $validated['is_selectable'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الحساب بنجاح',
            'data'    => $account,
        ]);
    }

    /**
     * Delete account with safety checks (fails if children exist or journal entries exist).
     */
    public function destroy(int $id): JsonResponse
    {
        $account = Account::findOrFail($id);

        // 1. Check if account has children
        if ($account->children()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف هذا الحساب لأنه يحتوي على حسابات فرعية متفرعة منه.',
            ], 422);
        }

        // 2. Check if account is referenced in financial transactions if table exists
        if (Schema::hasTable('financial_transactions')) {
            $hasTransactions = DB::table('financial_transactions')
                ->where('account_id', $id)
                ->exists();

            if ($hasTransactions) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا يمكن حذف هذا الحساب لوجود حركات مالية وقيود مسجلة عليه.',
                ], 422);
            }
        }

        $account->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الحساب بنجاح',
        ]);
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
