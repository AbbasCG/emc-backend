<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Department;
use App\Models\Registration;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Support\ProtectedAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{

    // GET /api/admin/users/search?q=
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $query = User::withTrashed()
            ->where('is_active', true)
            ->whereNull('deleted_at');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('name',  'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        $users = $query->orderBy('name')->limit(20)->get();

        return response()->json([
            'success' => true,
            'data'    => $users->map(fn (User $u) => [
                'id'     => $u->id,
                'name'   => $u->name,
                'email'  => $u->email,
                'phone'  => $u->phone,
                'avatar' => $u->avatar_url ?? null,
            ]),
        ]);
    }

    // GET /api/admin/users
    // ?status=active (default) | inactive | deleted | all
    public function index(Request $request): JsonResponse
    {
        $status = $request->string('status', 'active')->toString();

        $query = match ($status) {
            'deleted'  => User::onlyTrashed(),
            'all'      => User::withTrashed(),
            'inactive' => User::query()->where('is_active', false),
            default    => User::query()->where('is_active', true),   // 'active'
        };

        $query
            ->when($request->role, function ($q) use ($request) {
                $role = $request->role;
                // 'instructor' and 'teacher' are the same canonical role — match both.
                if ($role === 'instructor') {
                    $q->whereIn('role', ['instructor', 'teacher']);
                } else {
                    $q->where('role', $role);
                }
            })
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($sub) use ($request) {
                    $sub->where('name', 'like', "%{$request->search}%")
                        ->orWhere('email', 'like', "%{$request->search}%");
                });
            })
            ->orderBy('name');

        $users = $query->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json([
            'success' => true,
            'data'    => $users->map(fn (User $u) => $this->formatUser($u)),
            'meta'    => [
                'total'        => $users->total(),
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'per_page'     => $users->perPage(),
            ],
        ]);
    }

    // POST /api/admin/users
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                      => 'required|string|max:255',
            'email'                     => 'required|email|max:255|unique:users,email',
            'password'                  => 'nullable|string|min:8',
            'role'                      => ['required', Rule::in(Role::assignableSlugs())],
            'phone'                     => 'nullable|string|max:30',
            'department'                => 'nullable|string|max:255',
            'city'                      => 'nullable|string|max:100',
            'country'                   => 'nullable|string|max:100',
            'how_did_you_hear_about_us' => 'nullable|string|max:255',
            'is_active'                 => 'nullable|boolean',
        ]);

        // super_admin creation is restricted to super_admin requesters
        if ($data['role'] === 'super_admin' && ! $request->user()->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only super_admin can create another super_admin.',
            ], 403);
        }

        // Auto-generate a temporary password when none is provided.
        // The plaintext is sent via email ONLY — never returned in the API response.
        $usedTemporaryPassword = false;
        if (empty($data['password'])) {
            $temporaryPassword     = \Illuminate\Support\Str::random(12);
            $data['password']      = $temporaryPassword;
            $usedTemporaryPassword = true;
        }

        $user = User::create([
            'name'                      => $data['name'],
            'email'                     => $data['email'],
            'password'                  => Hash::make($data['password']),
            'role'                      => $data['role'],
            'phone'                     => $data['phone'] ?? null,
            'department'                => $data['department'] ?? null,
            'city'                      => $data['city'] ?? null,
            'country'                   => $data['country'] ?? null,
            'how_did_you_hear_about_us' => $data['how_did_you_hear_about_us'] ?? null,
            'is_active'                 => $data['is_active'] ?? true,
        ]);

        $deptInput = $request->input('department_id') ?? $data['department'] ?? null;
        $deptId = is_numeric($deptInput) ? (int) $deptInput : null;
        if (! $deptId && is_string($deptInput) && ! empty($deptInput)) {
            $matchedDept = Department::where('name_ar', $deptInput)
                ->orWhere('name', $deptInput)
                ->orWhere('slug', $deptInput)
                ->first();
            $deptId = $matchedDept?->id;
        }

        if ($deptId) {
            $user->departments()->sync([$deptId => ['is_primary' => true]]);
            $deptModel = Department::find($deptId);
            if ($deptModel && empty($user->department)) {
                $user->update(['department' => $deptModel->name_ar ?? $deptModel->name]);
            }

            if ($user->role === 'department_manager') {
                $deptModel?->update(['leader_id' => $user->id]);
            } elseif ($user->role === 'department_member') {
                $teamProfile = TeamProfile::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'department_id' => $deptId,
                        'name_ar'       => $user->name,
                        'position_ar'   => 'عضو إدارة',
                        'role_key'      => 'department_member',
                        'email'         => $user->email,
                        'is_active'     => true,
                        'is_leader'     => false,
                    ]
                );
                if ($teamProfile->department_id !== $deptId) {
                    $teamProfile->update(['department_id' => $deptId]);
                }
                TeamMember::updateOrCreate(
                    ['team_profile_id' => $teamProfile->id],
                    [
                        'user_id'       => $user->id,
                        'department_id' => $deptId,
                        'section_id'    => null, // unassigned, waiting for manager to seat them
                        'role_title'    => 'عضو إدارة',
                        'status'        => 'active',
                        'joined_at'     => now(),
                    ]
                );
            }
        }

        // Auto-create instructor profile so user appears in the instructors module
        if (in_array($user->role, ['instructor', 'teacher'], true)) {
            AdminInstructorController::ensureProfile($user);
        }

        // Send welcome email with temp password if generated, or plain welcome otherwise.
        // Routes through EmailService → NotificationLog (logged, respects email settings toggle).
        NotificationService::accountCreated(
            $user->id,
            $user->name,
            $usedTemporaryPassword ? $temporaryPassword : null
        );
        app(\App\Services\EmailVerificationService::class)->sendInitial($user, $request->user());

        AuditLogService::logModel('created', $user, null, [
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role,
        ], null, $request);

        return response()->json([
            'success' => true,
            'message' => $usedTemporaryPassword
                ? 'User created successfully. A temporary password has been sent to their email.'
                : 'User created successfully.',
            'data'    => $this->formatUser($user),
        ], 201);
    }

    // GET /api/admin/users/{user}
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->formatUser($user, withPermissions: true),
        ]);
    }

    // PUT /api/admin/users/{user}
    public function update(Request $request, User $user): JsonResponse
    {
        // Non-super_admin cannot edit super_admin accounts
        if ($user->isSuperAdmin() && ! $request->user()->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only super_admin can edit another super_admin.',
            ], 403);
        }

        $data = $request->validate([
            'name'                      => 'sometimes|string|max:255',
            'email'                     => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password'                  => 'sometimes|nullable|string|min:8',
            'role'                      => ['sometimes', Rule::in(Role::assignableSlugs())],
            'phone'                     => 'sometimes|nullable|string|max:30',
            'department'                => 'sometimes|nullable|string|max:255',
            'city'                      => 'sometimes|nullable|string|max:100',
            'country'                   => 'sometimes|nullable|string|max:100',
            'how_did_you_hear_about_us' => 'sometimes|nullable|string|max:255',
            'is_active'                 => 'sometimes|boolean',
            'remove_avatar'             => 'sometimes|boolean',
        ]);

        if ($request->hasFile('avatar')) {
            $request->validate([
                'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            ]);
        }

        if (isset($data['role']) && $data['role'] === 'super_admin' && ! $request->user()->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only super_admin can assign the super_admin role.',
            ], 403);
        }

        // Hash only when a non-empty password is provided; drop the key otherwise
        if (array_key_exists('password', $data)) {
            if (filled($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }
        }

        $oldRole = $user->role;
        $oldSnap = [
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role,
            'phone' => $user->phone,
            'is_active' => $user->is_active,
        ];

        if ($request->hasFile('avatar')) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $data['avatar'] = $request->file('avatar')->store('avatars', 'public');
        } elseif ($request->boolean('remove_avatar')) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $data['avatar'] = null;
        }

        unset($data['remove_avatar']);

        $emailChanged = isset($data['email']) && strtolower((string) $data['email']) !== strtolower((string) $user->email);
        if ($emailChanged) {
            // The old verification proves nothing about the new address.
        }

        $user->update($data);
        if ($emailChanged) {
            $user->forceFill(['email_verified_at' => null])->save();
            app(\App\Services\EmailVerificationService::class)->sendInitial($user->fresh(), $request->user());
        }

        $deptInput = $request->input('department_id') ?? $data['department'] ?? null;
        if ($deptInput !== null || array_key_exists('department', $data) || $request->has('department_id')) {
            $deptId = is_numeric($deptInput) ? (int) $deptInput : null;
            if (! $deptId && is_string($deptInput) && ! empty($deptInput)) {
                $matchedDept = Department::where('name_ar', $deptInput)
                    ->orWhere('name', $deptInput)
                    ->orWhere('slug', $deptInput)
                    ->first();
                $deptId = $matchedDept?->id;
            }

            if ($deptId) {
                $user->departments()->sync([$deptId => ['is_primary' => true]]);
                $deptModel = Department::find($deptId);
                $user->update(['department' => $deptModel ? ($deptModel->name_ar ?? $deptModel->name) : $deptInput]);

                if ($user->role === 'department_manager') {
                    Department::where('id', $deptId)->update(['leader_id' => $user->id]);
                    Department::where('leader_id', $user->id)->where('id', '!=', $deptId)->update(['leader_id' => null]);
                } elseif ($user->role === 'department_member') {
                    Department::where('leader_id', $user->id)->update(['leader_id' => null]);

                    $teamProfile = TeamProfile::firstOrCreate(
                        ['user_id' => $user->id],
                        [
                            'department_id' => $deptId,
                            'name_ar'       => $user->name,
                            'position_ar'   => 'عضو إدارة',
                            'role_key'      => 'department_member',
                            'email'         => $user->email,
                            'is_active'     => true,
                            'is_leader'     => false,
                        ]
                    );
                    if ($teamProfile->department_id !== $deptId) {
                        $teamProfile->update(['department_id' => $deptId]);
                    }
                    TeamMember::updateOrCreate(
                        ['team_profile_id' => $teamProfile->id],
                        [
                            'user_id'       => $user->id,
                            'department_id' => $deptId,
                            'section_id'    => null,
                            'role_title'    => 'عضو إدارة',
                            'status'        => 'active',
                            'joined_at'     => now(),
                        ]
                    );
                }
            } elseif (empty($deptInput)) {
                $user->departments()->detach();
                Department::where('leader_id', $user->id)->update(['leader_id' => null]);
                $user->update(['department' => null]);
            }
        } elseif ($oldRole === 'department_manager' && $user->role !== 'department_manager') {
            Department::where('leader_id', $user->id)->update(['leader_id' => null]);
        }

        if (in_array($user->fresh()->role, ['instructor', 'teacher'], true)) {
            AdminInstructorController::ensureProfile($user->fresh());
        }

        $action = isset($data['role']) && $data['role'] !== $oldRole ? 'role_changed' : 'updated';
        AuditLogService::logModel($action, $user->fresh(), $oldSnap, [
            'name'  => $user->fresh()->name,
            'email' => $user->fresh()->email,
            'role'  => $user->fresh()->role,
        ], null, $request);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data'    => $this->formatUser($user->fresh()),
        ]);
    }

    // DELETE /api/admin/users/{user}  — route allows super_admin+tech_admin;
    // deleting/deactivating a super_admin account specifically requires the
    // actor to actually be super_admin (fixes the confirmed gap where
    // tech_admin could deactivate/soft-delete any super_admin account).
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        if (ProtectedAuthority::isProtectedUser($user)) {
            ProtectedAuthority::requireSuperAdmin($request->user(), 'Only super_admin may delete a super_admin account.');
        }

        $snap = ['name' => $user->name, 'email' => $user->email, 'role' => $user->role];

        $user->tokens()->delete();
        $user->update(['is_active' => false]);
        $user->delete();

        AuditLogService::log('deleted', 'User', $user->id, $user->name, $snap, null, null, $request);

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }

    // POST /api/admin/users/{id}/restore  — route allows super_admin+tech_admin;
    // restoring a super_admin account specifically requires the actor to
    // actually be super_admin (same protected-authority boundary as destroy()).
    public function restore(Request $request, int $id): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($id);

        if (! $user->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'User is not deleted.',
            ], 422);
        }

        if (ProtectedAuthority::isProtectedUser($user)) {
            ProtectedAuthority::requireSuperAdmin($request->user(), 'Only super_admin may restore a super_admin account.');
        }

        $user->restore();
        $user->update(['is_active' => true]);

        AuditLogService::log('restored', 'User', $user->id, $user->name,
            null, ['name' => $user->name, 'email' => $user->email], null, $request);

        return response()->json([
            'success' => true,
            'message' => 'User restored successfully.',
            'data'    => $this->formatUser($user->fresh()),
        ]);
    }

    // GET /admin/stats
    public function stats(Request $request): JsonResponse
    {
        // Role already enforced by route middleware (admin,super_admin,tech_admin)
        $now            = now();
        $thisMonthStart = $now->copy()->startOfMonth();
        $prevMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $prevMonthEnd   = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $totalUsers    = User::count();
        $activeUsers   = User::where('is_active', true)->count();
        $inactiveUsers = User::where('is_active', false)->count();
        $newThisMonth  = User::where('created_at', '>=', $thisMonthStart)->count();
        $newLastMonth  = User::whereBetween('created_at', [$prevMonthStart, $prevMonthEnd])->count();

        // «الطلاب» here means people LEARNING on the platform (students plus anyone actually enrolled, whatever their role).
        $learners         = app(\App\Services\LearnerCounts::class);
        $totalStudents    = $learners->total();
        $activeStudents   = $learners->active();
        $studNewThisMonth = $learners->newBetween($thisMonthStart, $now);
        $studNewLastMonth = $learners->newBetween($prevMonthStart, $prevMonthEnd);

        $totalRegs    = Registration::count();
        $pendingRegs  = Registration::where(function ($q) {
            $q->whereIn('status', ['pending', 'processing'])->orWhereNull('status');
        })->count();
        $regsThisMonth = Registration::where('created_at', '>=', $thisMonthStart)->count();
        $regsLastMonth = Registration::whereBetween('created_at', [$prevMonthStart, $prevMonthEnd])->count();

        $totalCourses = Course::count();

        $pct = function (int $cur, int $last): ?float {
            if ($last === 0) return null;
            return round(($cur - $last) / $last * 100, 1);
        };

        return response()->json([
            'success' => true,
            'data'    => [
                'users' => [
                    'total'             => $totalUsers,
                    'active'            => $activeUsers,
                    'inactive'          => $inactiveUsers,
                    'new_this_month'    => $newThisMonth,
                    'new_last_month'    => $newLastMonth,
                    'change_percentage' => $pct($newThisMonth, $newLastMonth),
                ],
                'students' => [
                    'total'             => $totalStudents,
                    'active'            => $activeStudents,
                    'new_this_month'    => $studNewThisMonth,
                    'new_last_month'    => $studNewLastMonth,
                    'change_percentage' => $pct($studNewThisMonth, $studNewLastMonth),
                ],
                'registrations' => [
                    'total'             => $totalRegs,
                    'pending'           => $pendingRegs,
                    'new_this_month'    => $regsThisMonth,
                    'new_last_month'    => $regsLastMonth,
                    'change_percentage' => $pct($regsThisMonth, $regsLastMonth),
                ],
                'courses' => [
                    'total' => $totalCourses,
                ],
                'chart' => $this->buildChartData(),
            ],
        ]);
    }

    private function buildChartData(): array
    {
        $now    = now();
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $d     = $now->copy()->subMonthsNoOverflow($i);
            $start = $d->copy()->startOfMonth();
            $end   = $d->copy()->endOfMonth();
            $months[] = [
                'key'           => $d->format('Y-m'),
                'label'         => $d->locale('ar')->isoFormat('MMM'),
                'students'      => app(\App\Services\LearnerCounts::class)->newBetween($start, $end),
                'registrations' => Registration::whereBetween('created_at', [$start, $end])->count(),
            ];
        }
        return $months;
    }

    private function formatUser(User $user, bool $withPermissions = false): array
    {
        $data = [
            'id'                         => $user->id,
            'name'                       => $user->name,
            'email'                      => $user->email,
            'phone'                      => $user->phone,
            'phone_country_code'         => $user->phone_country_code,
            'role'                       => $user->role,
            'department'                 => $user->department,
            'department_id'              => $user->departments()->first()?->id,
            'department_name'            => $user->departments()->first()?->name_ar ?? $user->departments()->first()?->name ?? $user->department,
            'is_active'                  => $user->is_active ?? true,
            'city'                       => $user->city,
            'country'                    => $user->country,
            'country_code'               => $user->country_code,
            'gender'                     => $user->gender,
            'how_did_you_hear_about_us'  => $user->how_did_you_hear_about_us,
            'avatar_url'                 => $user->avatar_url,
            'email_verified_at'          => $user->email_verified_at,
            'email_verified'             => $user->email_verified_at !== null,
            // Offered only where a resend would actually be accepted (the route's role gate already limits who sees this module).
            'can_resend_verification'    => $user->email_verified_at === null && ($user->is_active ?? true) && $user->deleted_at === null,
            'last_login_at'              => $user->last_login_at,
            'deleted_at'                 => $user->deleted_at,
            'created_at'                 => $user->created_at,
            'updated_at'                 => $user->updated_at,
        ];

        if ($withPermissions) {
            $data['permissions'] = $user->allPermissions();
        }

        return $data;
    }
}
