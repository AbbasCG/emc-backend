<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamMemberResource;
use App\Models\Department;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\DepartmentLeadershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminTeamProfileController extends Controller
{
    private const USER_SELECT = 'id,name,email,role,avatar,is_active';

    public function __construct(private readonly DepartmentLeadershipService $leadership)
    {
    }

    /**
     * GET /api/admin/team
     *
     * All departments with ALL their team profiles (including inactive).
     */
    public function adminTeam(): JsonResponse
    {
        $departments = Department::query()
            ->where('is_active', true)
            ->whereNotNull('name_ar')
            ->whereNotNull('name_en')
            ->with(['teamProfiles' => fn ($q) => $q
                ->with('user:' . self::USER_SELECT)
                ->orderByDesc('is_executive')
                ->orderByDesc('is_leader')
                ->orderBy('sort_order')
            ])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $departments->map(fn ($dept) => [
                'id'             => $dept->id,
                'name_ar'        => $dept->name_ar ?: $dept->name,
                'name_en'        => $dept->name_en,
                'slug'           => $dept->slug,
                'description_ar' => $dept->description_ar ?: $dept->description,
                'icon'           => $dept->icon,
                'sort_order'     => $dept->sort_order,
                'members'        => TeamMemberResource::collection($dept->teamProfiles),
            ]),
        ]);
    }

    // GET /api/admin/team-profiles
    public function index(Request $request): JsonResponse
    {
        $profiles = TeamProfile::with([
            'department:id,name_ar,name_en,slug',
            'user:' . self::USER_SELECT,
        ])
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->input('is_active') !== null, fn ($q) => $q->where('is_active', (bool) $request->is_active))
            ->when($request->search, fn ($q) => $q->where(fn ($inner) => $inner
                ->where('name_ar', 'like', "%{$request->search}%")
                ->orWhere('name_en', 'like', "%{$request->search}%")
                ->orWhere('position_ar', 'like', "%{$request->search}%")
            ))
            ->orderByDesc('is_executive')
            ->orderByDesc('is_leader')
            ->orderBy('sort_order')
            ->paginate(min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'success' => true,
            'data'    => $profiles->map(fn ($p) => $this->format($p)),
            'meta'    => [
                'total'        => $profiles->total(),
                'current_page' => $profiles->currentPage(),
                'last_page'    => $profiles->lastPage(),
            ],
        ]);
    }

    // POST /api/admin/team-profiles
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name_ar'       => 'required|string|max:255',
            'name_en'       => 'nullable|string|max:255',
            'position_ar'   => 'required|string|max:255',
            'position_en'   => 'nullable|string|max:255',
            'department_id' => 'nullable|exists:departments,id',
            'user_id'       => 'nullable|exists:users,id',
            'role_key'      => 'nullable|string|max:100',
            'bio_ar'        => 'nullable|string|max:1000',
            'country'       => 'nullable|string|max:100',
            'email'         => 'nullable|email|max:255',
            'phone'         => 'nullable|string|max:50',
            'is_leader'     => 'boolean',
            'is_executive'  => 'boolean',
            'is_active'     => 'boolean',
            'sort_order'    => 'nullable|integer|min:0',
        ]);

        $profile = TeamProfile::create($data);
        $this->leadership->syncFromTeamProfile($profile);
        $this->loadRelations($profile);

        return response()->json(['success' => true, 'data' => $this->format($profile)], 201);
    }

    // GET /api/admin/team-profiles/{teamProfile}
    public function show(TeamProfile $teamProfile): JsonResponse
    {
        $this->loadRelations($teamProfile);

        return response()->json(['success' => true, 'data' => $this->format($teamProfile)]);
    }

    // PUT/PATCH /api/admin/team-profiles/{teamProfile}
    public function update(Request $request, TeamProfile $teamProfile): JsonResponse
    {
        $data = $request->validate([
            'name_ar'       => 'sometimes|string|max:255',
            'name_en'       => 'nullable|string|max:255',
            'position_ar'   => 'sometimes|string|max:255',
            'position_en'   => 'nullable|string|max:255',
            'department_id' => 'nullable|exists:departments,id',
            'user_id'       => 'nullable|exists:users,id',
            'role_key'      => 'nullable|string|max:100',
            'bio_ar'        => 'nullable|string|max:1000',
            'country'       => 'nullable|string|max:100',
            'email'         => 'nullable|email|max:255',
            'phone'         => 'nullable|string|max:50',
            'is_leader'     => 'boolean',
            'is_executive'  => 'boolean',
            'is_active'     => 'boolean',
            'sort_order'    => 'nullable|integer|min:0',
        ]);

        $departmentChanging = array_key_exists('department_id', $data)
            && $data['department_id'] != $teamProfile->department_id;
        $positionChanging = array_key_exists('position_ar', $data)
            && $data['position_ar'] !== $teamProfile->position_ar;

        $teamProfile->update($data);

        if (array_key_exists('is_leader', $data)) {
            $this->leadership->syncFromTeamProfile($teamProfile);
        }

        // Keep the operational team_members row (if any) from silently
        // drifting away from this canonical profile — see the architecture
        // audit. department_id/role_title are still duplicated on
        // team_members for now (Stage 1 of the non-destructive migration),
        // so they must be kept in sync here rather than left stale.
        if ($departmentChanging || $positionChanging) {
            $member = \App\Models\TeamMember::where('team_profile_id', $teamProfile->id)->first();
            if ($member) {
                $updates = [];
                if ($departmentChanging) {
                    $updates['department_id'] = $teamProfile->department_id;
                    // The old unit certainly no longer belongs to the new
                    // department (or department_id is now null) — a member
                    // cannot remain placed in a unit from another department.
                    if (
                        $member->unit_id && (
                            ! $teamProfile->department_id
                            || ! \App\Models\DepartmentalUnit::where('id', $member->unit_id)
                                ->where('department_id', $teamProfile->department_id)
                                ->exists()
                        )
                    ) {
                        $updates['unit_id'] = null;
                    }
                }
                if ($positionChanging) {
                    $updates['role_title'] = $teamProfile->position_ar;
                }
                $member->update($updates);
            }
        }

        $this->loadRelations($teamProfile);

        return response()->json(['success' => true, 'data' => $this->format($teamProfile)]);
    }

    // DELETE /api/admin/team-profiles/{teamProfile}
    public function destroy(TeamProfile $teamProfile): JsonResponse
    {
        // The operational team_members row (if any) intentionally RESTRICTs
        // this delete at the database level — an operational membership must
        // never silently lose its canonical profile. Surface that as a
        // controlled business error instead of a raw SQL 500.
        if (\App\Models\TeamMember::where('team_profile_id', $teamProfile->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف العضو لأنه مرتبط بعضوية تشغيلية. قم بإزالة ارتباطه التشغيلي أولاً.',
            ], 409);
        }

        if ($teamProfile->image) {
            Storage::disk('public')->delete($teamProfile->image);
        }

        $teamProfile->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف العضو بنجاح.']);
    }

    /**
     * POST /api/admin/team-profiles/{teamProfile}/photo
     */
    public function uploadPhoto(Request $request, TeamProfile $teamProfile): JsonResponse
    {
        $request->validate(['photo' => 'required|image|max:4096']);

        if ($teamProfile->image) {
            Storage::disk('public')->delete($teamProfile->image);
        }

        $path = $request->file('photo')->store('team/members', 'public');
        $teamProfile->update(['image' => $path]);
        $this->loadRelations($teamProfile);

        return response()->json(['success' => true, 'data' => $this->format($teamProfile)]);
    }

    /**
     * POST /api/admin/team-profiles/{teamProfile}/unlink-user
     *
     * Remove the user_id link from this team profile.
     * Does NOT delete the user account.
     */
    public function unlinkUser(TeamProfile $teamProfile): JsonResponse
    {
        $teamProfile->update(['user_id' => null]);
        $this->loadRelations($teamProfile);

        return response()->json([
            'success' => true,
            'message' => 'تم فك ربط الحساب بنجاح.',
            'data'    => $this->format($teamProfile),
        ]);
    }

    /**
     * POST /api/admin/team-profiles/{teamProfile}/create-user-account
     *
     * Three outcomes:
     *  A) already_linked  — profile already has user_id
     *  B) linked_existing — email matches an existing user → link
     *  C) created         — new user provisioned, temporary_password shown once
     */
    public function createUserAccount(TeamProfile $teamProfile): JsonResponse
    {
        if (! $teamProfile->email) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إنشاء حساب: ملف التعريف لا يحتوي على بريد إلكتروني.',
            ], 422);
        }

        // ── A: already linked ─────────────────────────────────────────────────
        if ($teamProfile->hasUserAccount()) {
            $teamProfile->load('user:' . self::USER_SELECT);

            return response()->json([
                'success' => true,
                'message' => 'هذا العضو لديه حساب مرتبط بالفعل.',
                'data'    => [
                    'member'              => $this->memberSnapshot($teamProfile),
                    'user'                => $this->userSnapshot($teamProfile->user),
                    'temporary_password'  => null,
                    'password_shown_once' => false,
                    'already_linked'      => true,
                    'linked_existing'     => false,
                    'created'             => false,
                ],
            ]);
        }

        // ── B: existing user with same email ──────────────────────────────────
        $existingUser = User::where('email', $teamProfile->email)->first();

        if ($existingUser) {
            $teamProfile->update(['user_id' => $existingUser->id]);
            $teamProfile->setRelation('user', $existingUser);

            return response()->json([
                'success' => true,
                'message' => 'تم ربط الحساب الموجود بالعضو.',
                'data'    => [
                    'member'              => $this->memberSnapshot($teamProfile),
                    'user'                => $this->userSnapshot($existingUser),
                    'temporary_password'  => null,
                    'password_shown_once' => false,
                    'already_linked'      => false,
                    'linked_existing'     => true,
                    'created'             => false,
                ],
            ]);
        }

        // ── C: create new user ────────────────────────────────────────────────
        $temporaryPassword = Str::password(12, letters: true, numbers: true, symbols: false);

        $user = User::create([
            'name'                 => $teamProfile->name_ar,
            'email'                => $teamProfile->email,
            'password'             => Hash::make($temporaryPassword),
            'must_change_password' => true,
            'phone'                => $teamProfile->phone,
            'city'                 => $teamProfile->city,
            'country'              => $teamProfile->country,
            'gender'               => $teamProfile->gender,
            'role'                 => 'volunteer',
            'is_active'            => true,
        ]);

        $teamProfile->update(['user_id' => $user->id]);
        $teamProfile->setRelation('user', $user);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء حساب المستخدم بنجاح.',
            'data'    => [
                'member'              => $this->memberSnapshot($teamProfile),
                'user'                => $this->userSnapshot($user),
                'temporary_password'  => $temporaryPassword,
                'password_shown_once' => true,
                'already_linked'      => false,
                'linked_existing'     => false,
                'created'             => true,
            ],
        ], 201);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function loadRelations(TeamProfile $p): void
    {
        $p->load([
            'department:id,name_ar,name_en,slug',
            'user:' . self::USER_SELECT,
        ]);
    }

    private function format(TeamProfile $p): array
    {
        $teamImage = $p->image
            ? (str_starts_with($p->image, 'http') ? $p->image : asset('storage/' . $p->image))
            : null;

        $userAvatar = null;
        if ($p->relationLoaded('user') && $p->user) {
            $raw = $p->user->avatar;
            $userAvatar = $raw
                ? (str_starts_with($raw, 'http') ? $raw : asset('storage/' . $raw))
                : null;
        }

        return [
            'id'                 => $p->id,
            'user_id'            => $p->user_id,
            'name_ar'            => $p->name_ar,
            'name_en'            => $p->name_en,
            'position_ar'        => $p->position_ar,
            'position_en'        => $p->position_en,
            'role_key'           => $p->role_key,
            'bio_ar'             => $p->bio_ar,
            'country'            => $p->country,
            'email'              => $p->email,
            'phone'              => $p->phone,
            'is_leader'          => (bool) $p->is_leader,
            'is_executive'       => (bool) $p->is_executive,
            'is_active'          => (bool) $p->is_active,
            'sort_order'         => $p->sort_order,
            'image'              => $teamImage,
            'user_avatar_url'    => $userAvatar,
            'resolved_photo_url' => $teamImage ?? $userAvatar,
            'department'         => $p->department ? [
                'id'      => $p->department->id,
                'name_ar' => $p->department->name_ar,
                'name_en' => $p->department->name_en,
                'slug'    => $p->department->slug,
            ] : null,
            'user'               => $p->user ? [
                'id'        => $p->user->id,
                'name'      => $p->user->name,
                'email'     => $p->user->email,
                'role'      => $p->user->role,
                'is_active' => (bool) $p->user->is_active,
                'avatar_url'=> $userAvatar,
            ] : null,
            'created_at'         => $p->created_at,
        ];
    }

    private function memberSnapshot(TeamProfile $m): array
    {
        return [
            'id'               => $m->id,
            'name'             => $m->name_ar,
            'email'            => $m->email,
            'user_id'          => $m->user_id,
            'has_user_account' => $m->user_id !== null,
        ];
    }

    private function userSnapshot(?User $u): ?array
    {
        if (! $u) return null;

        return [
            'id'        => $u->id,
            'name'      => $u->name,
            'email'     => $u->email,
            'role'      => $u->role,
            'is_active' => (bool) $u->is_active,
        ];
    }
}
