<?php

namespace App\Http\Controllers;

use App\Services\EffectivePageAccessService;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\EffectivePageAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 2D — the CURRENT USER's effective page access. Read-only.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION: this reports which dashboard pages
 * may be opened. It never reports, and must never be read as, permission to
 * perform an action.
 *
 * Mounted beside /api/auth/me and /api/auth/me/access-manifest because that is
 * where this platform already puts "what is true about the caller". It is
 * deliberately NOT merged into /auth/me/access-manifest: that endpoint returns
 * effective PERMISSIONS (business authorization, via
 * PermissionDelegationService) and blurring the two is precisely what the whole
 * Page Access program exists to prevent.
 *
 * Available to every authenticated user, because every authenticated user has
 * their own page access — there is nothing privileged about reading your own.
 *
 * PHASE 2D DOES NOT SWITCH THE FRONTEND. dashboardAccess.ts, the sidebar and
 * DashboardAccessGuard are untouched and still authoritative in production;
 * this endpoint exists so a later phase can migrate them deliberately.
 */
class EffectivePageAccessController extends Controller
{
    public function __construct(private readonly EffectivePageAccessService $service)
    {
    }

    /** GET /api/auth/me/page-access */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $resolved = $this->service->resolveAll($user);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role,
                ],
                // The efficient shape for a future sidebar/route guard.
                'allowed_keys' => array_values(array_map(
                    fn (EffectivePageAccess $r) => $r->key,
                    array_filter($resolved, fn (EffectivePageAccess $r) => $r->allowed)
                )),
                // Full provenance for every catalog page. Included alongside
                // allowed_keys so a denial can be explained ("محمية من النظام",
                // "محجوبة بشكل خاص") instead of rendering a blank redirect —
                // same resolver call, no extra queries.
                //
                // Each row also carries its catalog ROUTES, so the frontend can
                // map the URL it is about to render onto a capability without a
                // second request and without re-deriving the mapping itself.
                // PageAccessCatalog is a static in-memory declaration, so this
                // enrichment costs no queries.
                // Keyed BY PAGE KEY, which is what a route guard wants to look
                // up. array_values() here would silently turn the documented map
                // into a list and break every caller indexing it.
                'pages' => array_map(
                    function (EffectivePageAccess $r): array {
                        $entry = PageAccessCatalog::find($r->key);

                        return $r->toArray() + [
                            'primary_route' => $entry?->primaryRoute,
                            'route_patterns' => $entry?->routePatterns ?? [],
                            'label_ar' => $entry?->labelAr,
                            // Navigation metadata: lets the sidebar place a page the user was
                            // granted (by a role/department default or an override) under the
                            // right heading even when that role's static menu does not list it.
                            'category' => $entry?->category->value,
                            'category_label_ar' => $entry?->category->labelAr(),
                            'risk_level' => $entry?->riskLevel->value,
                        ];
                    },
                    $resolved
                ),
            ],
        ]);
    }
}
