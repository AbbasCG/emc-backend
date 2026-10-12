<?php

namespace App\Http\Controllers;

use App\Http\Resources\TeamDepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    /**
     * GET /api/team
     *
     * Return all active departments with their active team profiles.
     * Departments ordered by sort_order.
     * Members ordered by: is_executive desc → is_leader desc → sort_order asc.
     *
     * No authentication required — this is a public endpoint for the website.
     */
    public function index(Request $request): JsonResponse
    {
        // Only return departments that belong to the public team display:
        // they must have `is_active = true` AND `name_ar` set (our EMC V3 seeder
        // always populates name_ar, while old operational departments do not have it yet).
        // active() excludes archived departments (merged-away duplicates). The
        // is_active flag alone is not enough: a merge archives via `status`.
        $departments = Department::query()
            ->active()
            ->where('is_active', true)
            ->whereNotNull('name_ar')
            ->whereNotNull('name_en')   // EMC V3 team depts always have a name_en
            ->with(['activeTeamProfiles' => fn ($q) => $q->with('user:id,avatar,name,email,role,is_active')])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => TeamDepartmentResource::collection($departments),
        ]);
    }
}
