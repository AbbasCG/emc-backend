<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UsersAnalyticsRequest;
use App\Models\User;
use App\Services\UsersAnalytics\UsersAcquisitionReport;
use App\Services\UsersAnalytics\UsersActivityReport;
use App\Services\UsersAnalytics\UsersOverviewReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Users Management hub — analytics tabs (read-only aggregates).
 *
 *   GET /api/admin/users-analytics/overview      users.analytics.view
 *   GET /api/admin/users-analytics/activity      users.activity.view
 *   GET /api/admin/users-analytics/acquisition   users.acquisition.view
 *   GET /api/admin/users-analytics/last-logins   users.activity.view   (paginated)
 *
 * Authorization is the route's `permission:` middleware (super_admin / tech_admin pass it; everyone else needs the
 * explicit key). Seeing the users table is deliberately NOT enough. Responses hold aggregates only, so they are cached
 * briefly and shared between holders of the same key.
 */
class UsersAnalyticsController extends Controller
{
    public function overview(UsersAnalyticsRequest $request, UsersOverviewReport $report): JsonResponse
    {
        return $this->cached('overview', $request, fn () => $report->build($request->period(), $request->wantsComparison()));
    }

    public function activity(UsersAnalyticsRequest $request, UsersActivityReport $report): JsonResponse
    {
        return $this->cached('activity', $request, fn () => $report->build($request->period(), $request->wantsComparison()));
    }

    public function acquisition(UsersAnalyticsRequest $request, UsersAcquisitionReport $report): JsonResponse
    {
        return $this->cached('acquisition', $request, fn () => $report->build($request->period(), $request->wantsComparison()));
    }

    /**
     * Accounts ordered by their most recent login. Names and roles only — no email, phone or IP. `last_login_at` is the
     * time of the latest password login; it does not move for sessions that continue without a new login.
     */
    public function lastLogins(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $page = User::query()
            ->whereNotNull('last_login_at')
            ->orderByDesc('last_login_at')
            ->orderByDesc('id')
            ->select(['id', 'name', 'role', 'is_active', 'last_login_at'])
            ->paginate((int) ($data['per_page'] ?? 20), ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'success' => true,
            'data'    => collect($page->items())->map(fn (User $u) => [
                'id'            => $u->id,
                'name'          => $u->name,
                'role'          => $u->role,
                'is_active'     => (bool) $u->is_active,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ])->values(),
            'meta'    => [
                'total'        => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
            ],
        ]);
    }

    /** @param \Closure(): array<string, mixed> $build */
    private function cached(string $endpoint, UsersAnalyticsRequest $request, \Closure $build): JsonResponse
    {
        $ttl = (int) config('users-analytics.cache_ttl', 300);
        $period = $request->period();
        $key = 'users-analytics:'.$endpoint.':'.$period->cacheKey().':'.($request->wantsComparison() ? 'cmp' : 'solo');

        $data = $ttl > 0 ? Cache::remember($key, $ttl, $build) : $build();

        return response()->json(['success' => true, 'data' => $data]);
    }
}
