<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TeamProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/members
 *
 * Returns all team_profiles as the "members" list used by the الأعضاء dashboard
 * page. Includes converted volunteers (source = volunteer_request), manually
 * added org-chart profiles, etc.
 *
 * The response shape is deliberately flat to match InternalMember in membersApi.ts:
 *   name, email, phone, department, department_id, role_label, member_type,
 *   is_new, status, skills, source, volunteer_request_id, created_at
 */
class AdminMemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TeamProfile::with([
                'department:id,name_ar,slug',
                'user:id,name,email,role',
            ])
            ->when($request->member_type, fn ($q) => $q->where('member_type', $request->member_type))
            ->when($request->is_new,      fn ($q) => $q->where('is_new_member', true))
            ->when($request->search, function ($q) use ($request) {
                $term = $request->search;
                $q->where(function ($sub) use ($term) {
                    $sub->where('name_ar',  'like', "%{$term}%")
                        ->orWhere('email',  'like', "%{$term}%")
                        ->orWhere('phone',  'like', "%{$term}%");
                });
            })
            ->latest();

        $perPage = min((int) $request->query('per_page', 50), 200);
        $page    = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $page->map(fn (TeamProfile $m) => $this->format($m)),
            'meta'    => [
                'total'        => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    public function show(TeamProfile $member): JsonResponse
    {
        $member->load(['department:id,name_ar,slug', 'user:id,name,email,role']);

        return response()->json([
            'success' => true,
            'data'    => $this->format($member),
        ]);
    }

    private function format(TeamProfile $m): array
    {
        $hasAccount = $m->user_id !== null;

        return [
            'id'                      => $m->id,
            'name'                    => $m->name_ar,
            'email'                   => $m->email,
            'phone'                   => $m->phone,
            'avatar_url'              => $m->image
                ? (str_starts_with($m->image, 'http') ? $m->image : asset('storage/' . $m->image))
                : null,
            'department'              => $m->department?->name_ar,
            'department_id'           => $m->department_id ? (string) $m->department_id : null,
            'role_label'              => $m->position_ar,
            'member_type'             => $m->member_type ?? 'volunteer',
            'is_new'                  => (bool) $m->is_new_member,
            'status'                  => $m->is_active ? 'active' : 'inactive',
            'skills'                  => $m->skills,
            'source'                  => $m->source,
            'volunteer_request_id'    => $m->source === 'volunteer_request' ? $m->source_id : null,
            'joined_at'               => $m->joined_at,
            'created_at'              => $m->created_at,
            // ── Account linking fields ────────────────────────────────────────
            'user_id'                 => $m->user_id,
            'has_user_account'        => $hasAccount,
            'can_create_user_account' => ! $hasAccount && $m->email !== null,
            'linked_user'             => $hasAccount && $m->relationLoaded('user') && $m->user
                ? [
                    'id'    => $m->user->id,
                    'name'  => $m->user->name,
                    'email' => $m->user->email,
                    'role'  => $m->user->role,
                ]
                : null,
        ];
    }
}
