<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserPageAccessOverrideService;
use App\Support\PageAccess\PageAccessEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 2C — per-user PAGE ACCESS override configuration API.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION: nothing here grants a business
 * capability.
 *
 * All authority lives in UserPageAccessOverrideService — this controller never
 * re-derives who may configure what, nor which pages are eligible. The route
 * middleware is only the usual coarse pre-filter; a department leader reaching
 * these endpoints is still constrained to users in departments they actually
 * lead and to delegatable pages, and a super_admin target is refused for
 * anyone but a super_admin.
 */
class UserPageAccessOverrideController extends Controller
{
    public function __construct(private readonly UserPageAccessOverrideService $service)
    {
    }

    /**
     * GET /api/admin/users/{user}/page-access-overrides
     *
     * Returns the target's current overrides plus the pages THIS actor may set
     * for them. `grantable` is actor-scoped on purpose: a department leader is
     * shown only the 37 delegatable pages, so a future User Access Drawer
     * cannot even offer an action the backend would refuse, and never has to
     * re-implement the authority rule client-side.
     *
     * Pages absent from `overrides` are DEFAULT (inherit role/department layers).
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        try {
            $this->service->assertCanConfigure($actor, $user);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        }

        $grantable = $this->service->grantableKeysForActor($actor);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                // page_key => 'allow'|'deny'. Absent = DEFAULT.
                'overrides' => (object) $this->service->getOverrides($user),
                'grantable' => array_values(array_map(
                    static fn (PageAccessEntry $e) => [
                        'key' => $e->key,
                        'label_ar' => $e->labelAr,
                        'category' => $e->category->value,
                        'category_label_ar' => $e->category->labelAr(),
                        'risk_level' => $e->riskLevel->value,
                        'delegatable' => $e->delegatable,
                    ],
                    array_filter(
                        $this->service->eligibleEntries(),
                        fn (PageAccessEntry $e) => in_array($e->key, $grantable, true)
                    )
                )),
            ],
        ]);
    }

    /**
     * PUT /api/admin/users/{user}/page-access-overrides
     *
     * Atomic full replace of the user's override set.
     *
     * Body: { "allow": ["page.key", ...], "deny": ["page.key", ...] }
     *
     * Replace (rather than per-page POST/DELETE) was chosen because the future
     * drawer needs "Allow all / Deny all / Reset all" per category: any page
     * omitted from both arrays is reset to DEFAULT by deleting its row, so a
     * whole category reset is one request, validated up front, applied in one
     * transaction, with no partial state on failure.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'allow' => 'present|array',
            'allow.*' => 'string',
            'deny' => 'present|array',
            'deny.*' => 'string',
        ]);

        try {
            $overrides = $this->service->replaceOverrides($actor, $user, $data['allow'], $data['deny']);
        } catch (RuntimeException $e) {
            // RuntimeException = authority failure; InvalidArgumentException
            // (a subclass of LogicException) = validation failure, caught below.
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث صلاحيات الصفحات الخاصة بالمستخدم بنجاح',
            'data' => ['overrides' => (object) $overrides],
        ]);
    }
}
