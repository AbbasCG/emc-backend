<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessCategory;
use Illuminate\Http\JsonResponse;

/**
 * Read-only Page Access Catalog API.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION — see PageAccessCatalog's class
 * docblock. This endpoint only exposes catalog metadata (which dashboard
 * pages exist and their preliminary classification); it never grants or
 * checks any actual permission.
 *
 * Restricted to super_admin/tech_admin for this Phase 1 foundation (route
 * middleware). Broadening read access to department leaders, for a future
 * delegation UI, is deferred to a later phase.
 */
class PageAccessCatalogController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'pages' => PageAccessCatalog::toArray(),
                'categories' => array_map(
                    static fn (PageAccessCategory $c) => ['key' => $c->value, 'label_ar' => $c->labelAr()],
                    PageAccessCategory::cases()
                ),
            ],
        ]);
    }
}
