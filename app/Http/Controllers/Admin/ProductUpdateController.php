<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductUpdateResource;
use App\Models\AuditLog;
use App\Models\PlatformNotification;
use App\Models\ProductUpdate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductUpdateController extends Controller
{
    // ─── List ─────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $isAdmin = $user->isSuperAdmin() || $user->isTechAdmin()
            || $user->hasPermission(\App\Support\Permissions::MANAGE_PRODUCT_UPDATES);

        $query = ProductUpdate::with('creator')
            ->withCount('reads')
            ->orderByDesc('published_at')
            ->orderByDesc('created_at');

        if (! $isAdmin) {
            $query->published()->forRole($user->role);
        }

        if ($request->filled('status') && $isAdmin) {
            $query->where('status', $request->status);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('q')) {
            $query->where('title', 'like', '%' . $request->q . '%');
        }

        $items = $query->paginate($request->integer('per_page', 20));

        // Eager-load reads for the current user
        $ids = $items->pluck('id');
        $readIds = $user
            ? \App\Models\ProductUpdateRead::where('user_id', $user->id)
                ->whereIn('product_update_id', $ids)
                ->pluck('product_update_id')
                ->flip()
            : collect();

        $items->getCollection()->each(function ($item) use ($readIds) {
            $item->setRelation('reads', collect($readIds->has($item->id)
                ? [new \App\Models\ProductUpdateRead(['user_id' => request()->user()?->id])]
                : []));
        });

        return response()->json([
            'success' => true,
            'data'    => ProductUpdateResource::collection($items->items()),
            'meta'    => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'total'        => $items->total(),
                'per_page'     => $items->perPage(),
            ],
        ]);
    }

    // ─── Store ────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ProductUpdate::class);

        $data = $request->validate($this->updateRules('required'));

        $update = ProductUpdate::create([
            ...$data,
            'status'     => 'draft',
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'user_name'   => $request->user()->name,
            'user_email'  => $request->user()->email,
            'user_role'   => $request->user()->role,
            'action'      => 'CREATE',
            'entity_type' => 'ProductUpdate',
            'entity_id'   => $update->id,
            'entity_name' => $update->title,
            'description' => 'Created product update: ' . $update->title,
        ]);

        $update->load('creator');
        $update->setRelation('reads', collect());

        return response()->json(['success' => true, 'data' => new ProductUpdateResource($update)], 201);
    }

    // ─── Show ─────────────────────────────────────────────────────────────────

    public function show(Request $request, ProductUpdate $productUpdate): JsonResponse
    {
        $this->authorize('view', $productUpdate);

        $productUpdate->load('creator');
        $user = $request->user();
        $reads = $user
            ? \App\Models\ProductUpdateRead::where('user_id', $user->id)
                ->where('product_update_id', $productUpdate->id)
                ->get()
            : collect();
        $productUpdate->setRelation('reads', $reads);
        $productUpdate->reads_count = $productUpdate->reads()->count();

        return response()->json(['success' => true, 'data' => new ProductUpdateResource($productUpdate)]);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function update(Request $request, ProductUpdate $productUpdate): JsonResponse
    {
        $this->authorize('update', $productUpdate);

        $data = $request->validate($this->updateRules('sometimes'));

        $productUpdate->update([...$data, 'updated_by' => $request->user()->id]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'user_name'   => $request->user()->name,
            'user_email'  => $request->user()->email,
            'user_role'   => $request->user()->role,
            'action'      => 'UPDATE',
            'entity_type' => 'ProductUpdate',
            'entity_id'   => $productUpdate->id,
            'entity_name' => $productUpdate->title,
            'description' => 'Updated product update: ' . $productUpdate->title,
        ]);

        $productUpdate->load('creator');
        $user = $request->user();
        $reads = \App\Models\ProductUpdateRead::where('user_id', $user->id)
            ->where('product_update_id', $productUpdate->id)->get();
        $productUpdate->setRelation('reads', $reads);
        $productUpdate->reads_count = $productUpdate->reads()->count();

        return response()->json(['success' => true, 'data' => new ProductUpdateResource($productUpdate)]);
    }

    // ─── Destroy ──────────────────────────────────────────────────────────────

    public function destroy(Request $request, ProductUpdate $productUpdate): JsonResponse
    {
        $this->authorize('delete', $productUpdate);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'user_name'   => $request->user()->name,
            'user_email'  => $request->user()->email,
            'user_role'   => $request->user()->role,
            'action'      => 'DELETE',
            'entity_type' => 'ProductUpdate',
            'entity_id'   => $productUpdate->id,
            'entity_name' => $productUpdate->title,
            'description' => 'Deleted product update: ' . $productUpdate->title,
        ]);

        $productUpdate->delete();

        return response()->json(['success' => true, 'message' => 'Deleted.']);
    }

    // ─── Publish ──────────────────────────────────────────────────────────────

    public function publish(Request $request, ProductUpdate $productUpdate): JsonResponse
    {
        $this->authorize('publish', $productUpdate);

        if ($productUpdate->status === 'published') {
            return response()->json(['success' => false, 'message' => 'Already published.'], 422);
        }

        $productUpdate->update([
            'status'       => 'published',
            'published_at' => now(),
            'updated_by'   => $request->user()->id,
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'user_name'   => $request->user()->name,
            'user_email'  => $request->user()->email,
            'user_role'   => $request->user()->role,
            'action'      => 'PUBLISH',
            'entity_type' => 'ProductUpdate',
            'entity_id'   => $productUpdate->id,
            'entity_name' => $productUpdate->title,
            'description' => 'Published product update: ' . $productUpdate->title,
        ]);

        // Send in-app notifications
        if ($productUpdate->notify_in_app) {
            $this->dispatchInAppNotifications($productUpdate);
        }

        $productUpdate->load('creator');
        $productUpdate->setRelation('reads', collect());
        $productUpdate->reads_count = 0;

        return response()->json(['success' => true, 'data' => new ProductUpdateResource($productUpdate)]);
    }

    // ─── Mark Read ────────────────────────────────────────────────────────────

    public function markRead(Request $request, ProductUpdate $productUpdate): JsonResponse
    {
        $user = $request->user();

        \App\Models\ProductUpdateRead::updateOrCreate(
            ['product_update_id' => $productUpdate->id, 'user_id' => $user->id],
            ['read_at' => now()],
        );

        return response()->json(['success' => true]);
    }

    // ─── Unread Count ─────────────────────────────────────────────────────────

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = ProductUpdate::published()
            ->forRole($user->role)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))
            ->count();

        return response()->json(['success' => true, 'count' => $count]);
    }

    // ─── What's New ───────────────────────────────────────────────────────────

    public function whatsNew(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = ProductUpdate::published()
            ->forRole($user->role)
            ->with('creator')
            ->withCount('reads')
            ->orderByDesc('published_at')
            ->limit(10)
            ->get();

        $readIds = \App\Models\ProductUpdateRead::where('user_id', $user->id)
            ->whereIn('product_update_id', $items->pluck('id'))
            ->pluck('product_update_id')
            ->flip();

        $items->each(function ($item) use ($readIds, $user) {
            $item->setRelation('reads', $readIds->has($item->id)
                ? collect([new \App\Models\ProductUpdateRead(['user_id' => $user->id])])
                : collect());
        });

        return response()->json([
            'success' => true,
            'data'    => ProductUpdateResource::collection($items),
        ]);
    }

    // ─── Stats (admin only) — route: GET product-updates/admin-stats ────────

    public function stats(Request $request): JsonResponse
    {
        $this->authorize('create', ProductUpdate::class);

        return response()->json([
            'success' => true,
            'data'    => [
                'total'     => ProductUpdate::count(),
                'published' => ProductUpdate::where('status', 'published')->count(),
                'draft'     => ProductUpdate::where('status', 'draft')->count(),
                'archived'  => ProductUpdate::where('status', 'archived')->count(),
            ],
        ]);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    // ─── Shared validation rules ──────────────────────────────────────────────

    private function updateRules(string $presence): array
    {
        $types = 'information,new_feature,improvement,redesign,bug_fix,announcement,maintenance,security_update,action_required,mandatory_update';

        return [
            'title'                    => "{$presence}|string|max:255",
            'body'                     => "{$presence}|string",
            'category'                 => "{$presence}|in:feature,fix,improvement,security,announcement",
            'update_type'              => "{$presence}|in:{$types}",
            'target_roles'             => 'nullable|array',
            'target_roles.*'           => 'string',
            'notify_in_app'            => 'boolean',
            'notify_email'             => 'boolean',
            'scheduled_at'             => 'nullable|date',
            // CTA
            'cta_label'                => 'nullable|string|max:100',
            'cta_url'                  => 'nullable|string|max:500',
            'cta_external'             => 'boolean',
            // Images
            'image_url'                => 'nullable|string|max:500',
            'image_before_url'         => 'nullable|string|max:500',
            'image_after_url'          => 'nullable|string|max:500',
            // Maintenance
            'maintenance_start'        => 'nullable|date',
            'maintenance_end'          => 'nullable|date|after_or_equal:maintenance_start',
            'affected_services'        => 'nullable|array',
            'affected_services.*'      => 'string|max:100',
            'maintenance_severity'     => 'nullable|in:low,medium,high,critical',
            // Action / Security
            'due_date'                 => 'nullable|date',
            'assigned_to_roles'        => 'nullable|array',
            'assigned_to_roles.*'      => 'string',
            'priority'                 => 'nullable|in:low,medium,high,critical',
            'requires_acknowledgement' => 'boolean',
            // Bug fix
            'problem_description'      => 'nullable|string',
            'fix_description'          => 'nullable|string',
            'affected_users'           => 'nullable|string|max:255',
            // Misc
            'related_page_url'         => 'nullable|string|max:500',
        ];
    }

    private function dispatchInAppNotifications(ProductUpdate $update): void
    {
        $query = User::where('is_active', true);

        if (! empty($update->target_roles)) {
            $query->whereIn('role', $update->target_roles);
        }

        $typeLabels = [
            'information'    => 'معلومات',
            'new_feature'    => 'ميزة جديدة',
            'improvement'    => 'تحسين',
            'redesign'       => 'إعادة تصميم',
            'bug_fix'        => 'إصلاح خطأ',
            'announcement'   => 'إعلان عام',
            'maintenance'    => 'صيانة',
            'security_update' => 'تحديث أمني',
            'action_required' => 'يتطلب إجراء',
            'mandatory_update' => 'تحديث إلزامي',
            // Legacy category fallback
            'feature'        => 'ميزة جديدة',
            'fix'            => 'إصلاح',
            'security'       => 'أمان',
        ];

        $categoryLabel = $typeLabels[$update->update_type ?? $update->category] ?? 'تحديث';

        $query->select('id')->chunkById(200, function ($users) use ($update, $categoryLabel) {
            $now  = now();
            $rows = $users->map(fn ($u) => [
                'user_id'     => $u->id,
                'type'        => 'product_update',
                'title'       => $update->title,
                'message'     => mb_strimwidth(strip_tags($update->body), 0, 120, '...'),
                'entity_type' => 'ProductUpdate',
                'entity_id'   => $update->id,
                'action_url'  => '/dashboard/whats-new',
                'icon'        => 'megaphone',
                'color'       => 'blue',
                'is_read'     => false,
                'created_by'  => $update->created_by,
                'created_at'  => $now,
                'updated_at'  => $now,
            ])->toArray();

            PlatformNotification::insert($rows);
        });
    }
}
