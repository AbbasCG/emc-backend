<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TemporaryPasswordMail;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    private const ALLOWED_ROLES = [
        'super_admin', 'executive_admin', 'admin', 'department_manager',
        'section_lead', 'finance_manager', 'marketing_manager', 'quality_manager',
        'hr_manager', 'support_agent', 'instructor', 'teacher',
        'student', 'volunteer', 'partner',
    ];

    // GET /api/admin/users
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->when($request->role, fn ($q) => $q->where('role', $request->role))
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($sub) use ($request) {
                    $sub->where('name', 'like', "%{$request->search}%")
                        ->orWhere('email', 'like', "%{$request->search}%");
                });
            })
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 20), 100));

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
            'role'                      => ['required', Rule::in(self::ALLOWED_ROLES)],
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

        // Auto-create instructor profile so user appears in the instructors module
        if (in_array($user->role, ['instructor', 'teacher'], true)) {
            AdminInstructorController::ensureProfile($user);
        }

        if ($usedTemporaryPassword) {
            // Queue the email — plaintext never leaves the server via the API response
            Mail::to($user->email)->queue(new TemporaryPasswordMail($user, $temporaryPassword));
        }

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
            'role'                      => ['sometimes', Rule::in(self::ALLOWED_ROLES)],
            'phone'                     => 'sometimes|nullable|string|max:30',
            'department'                => 'sometimes|nullable|string|max:255',
            'city'                      => 'sometimes|nullable|string|max:100',
            'country'                   => 'sometimes|nullable|string|max:100',
            'how_did_you_hear_about_us' => 'sometimes|nullable|string|max:255',
            'is_active'                 => 'sometimes|boolean',
        ]);

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

        $user->update($data);

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

    // DELETE /api/admin/users/{user}  — super_admin only (enforced in route)
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        $snap = ['name' => $user->name, 'email' => $user->email, 'role' => $user->role];

        $user->tokens()->delete();
        $user->delete();

        AuditLogService::log('deleted', 'User', $user->id, $user->name, $snap, null, null, $request);

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }

    private function formatUser(User $user, bool $withPermissions = false): array
    {
        $data = [
            'id'                         => $user->id,
            'name'                       => $user->name,
            'email'                      => $user->email,
            'phone'                      => $user->phone,
            'role'                       => $user->role,
            'department'                 => $user->department,
            'is_active'                  => $user->is_active ?? true,
            'city'                       => $user->city,
            'country'                    => $user->country,
            'how_did_you_hear_about_us'  => $user->how_did_you_hear_about_us,
            'avatar_url'                 => $user->avatar_url,
            'email_verified_at'          => $user->email_verified_at,
            'last_login_at'              => $user->last_login_at,
            'created_at'                 => $user->created_at,
            'updated_at'                 => $user->updated_at,
        ];

        if ($withPermissions) {
            $data['permissions'] = $user->allPermissions();
        }

        return $data;
    }
}
