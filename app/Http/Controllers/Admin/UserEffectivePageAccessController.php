<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EffectivePageAccessService;
use App\Services\UserPageAccessOverrideService;
use App\Support\PageAccess\EffectivePageAccess;
use App\Support\PageAccess\PageAccessCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Phase 2D — inspect ANOTHER user's effective page access, with provenance.
 * Read-only.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION: this explains why a dashboard page is
 * or is not exposed to someone. It says nothing about what they may do on it.
 *
 * ── Authorization ────────────────────────────────────────────────────────────
 * Deliberately reuses UserPageAccessOverrideService::canConfigure() rather than
 * inventing a second authority rule. That gives, with zero new surface:
 *   - super_admin / tech_admin -> any user
 *   - a canonical department leader (departments.leader_id via
 *     DepartmentAccessService) -> only users in departments they ACTUALLY lead
 *   - a super_admin target -> super_admin only (ProtectedAuthority)
 *   - anyone else -> 403
 * "May configure this user's overrides" strictly implies "may see what those
 * overrides resolve to", so inheriting it can only ever be narrower than a
 * bespoke rule, never wider — which is what closes the IDOR.
 *
 * One deliberate consequence: canConfigure() refuses a leader acting on
 * THEMSELVES, so a leader cannot inspect their own effective access here. That
 * is not a gap — /api/auth/me/page-access serves exactly that, for every
 * authenticated user.
 *
 * The route's role middleware is only the usual coarse pre-filter; the real
 * authority is the service check below.
 */
class UserEffectivePageAccessController extends Controller
{
    public function __construct(
        private readonly EffectivePageAccessService $service,
        private readonly UserPageAccessOverrideService $overrides,
    ) {
    }

    /** GET /api/admin/users/{user}/effective-page-access */
    public function show(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        try {
            $this->overrides->assertCanConfigure($actor, $user);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        }

        $resolved = $this->service->resolveAll($user);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'allowed_keys' => array_values(array_map(
                    fn (EffectivePageAccess $r) => $r->key,
                    array_filter($resolved, fn (EffectivePageAccess $r) => $r->allowed)
                )),
                // Catalog labels are merged in so the Access Control UI can
                // render a readable row per page without a second request.
                'pages' => array_values(array_map(
                    function (EffectivePageAccess $r) {
                        $entry = PageAccessCatalog::find($r->key);

                        return $r->toArray() + [
                            'label_ar' => $entry?->labelAr,
                            'category' => $entry?->category->value,
                            'category_label_ar' => $entry?->category->labelAr(),
                            'risk_level' => $entry?->riskLevel->value,
                        ];
                    },
                    $resolved
                )),
            ],
        ]);
    }
}
