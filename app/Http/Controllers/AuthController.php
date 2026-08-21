<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name'               => $request->name,
            'email'              => $request->email,
            'password'           => Hash::make($request->password),
            'role'               => 'student', // Self-registration always creates a student
            'country_code'       => $request->country_code,
            'phone_country_code' => $request->phone_country_code,
            'phone'              => $request->phone,
            'city'               => $request->city,
            'gender'             => $request->gender,
            'how_did_you_hear_about_us' => $request->how_did_you_hear_about_us,
        ]);

        $token = $this->establishAuthentication($request, $user);

        NotificationService::accountCreated($user->id, $user->name);

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'data'    => [
                'user'                => new UserResource($user),
                'role'                => $user->effectiveRole(),
                'permissions'         => $user->allPermissions(),
                'token'               => $token,
                'has_english_courses' => $this->hasEnglishCourses($user),
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::withTrashed()->where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            // Log failed login attempt if user exists
            if ($user) {
                AuditLogService::log(
                    action:      'LOGIN_FAILED',
                    entityType:  'User',
                    entityId:    $user->id,
                    entityName:  $user->name,
                    description: 'Failed login attempt for ' . $user->email,
                );
            }
            throw ValidationException::withMessages([
                'email' => ['البريد الإلكتروني أو كلمة المرور غير صحيحة'],
            ]);
        }

        if (! $user->is_active || $user->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Account is suspended.',
            ], 403);
        }

        // Revoke previous API tokens (the existing single-token contract).
        // Cookie sessions are invalidated through the session store on logout.
        $user->tokens()->delete();

        $user->update(['last_login_at' => now()]);

        $token = $this->establishAuthentication($request, $user);

        AuditLogService::logLogin($user, $request);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data'    => [
                'user'                => new UserResource($user),
                'role'                => $user->effectiveRole(),
                'permissions'         => $user->allPermissions(),
                'token'               => $token,
                'has_english_courses' => $this->hasEnglishCourses($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessToken = $user?->currentAccessToken();

        if ($request->bearerToken() && $accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        AuditLogService::logLogout($user, $request);

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user            = $request->user();
        $isImpersonating = (bool) $request->attributes->get('is_impersonating', false);

        $isDepartmentLeader = \App\Models\TeamProfile::where('user_id', $user->id)
            ->where('is_active', true)
            ->where('is_leader', true)
            ->exists();

        $data = [
            'user'                 => new UserResource($user),
            'role'                 => $user->role,
            'permissions'          => $user->allPermissions(),
            'is_department_leader' => $isDepartmentLeader,
            'is_impersonating'     => $isImpersonating,
            'has_english_courses'  => $this->hasEnglishCourses($user),
        ];

        if ($isImpersonating) {
            $impersonatorId = $request->attributes->get('impersonator_id');
            $impersonator   = $impersonatorId ? \App\Models\User::find($impersonatorId) : null;

            $data['impersonator'] = $impersonator ? [
                'id'   => $impersonator->id,
                'name' => $impersonator->name,
                'role' => $impersonator->effectiveRole(),
            ] : null;
        }

        return response()->json([
            'success' => true,
            'message' => 'Authenticated user retrieved',
            'data'    => $data,
        ]);
    }

    /**
     * English Institute gate: derived solely from courses.requires_placement_test
     * via Instructor::hasEnglishCourses() — never from role/department/title.
     * Shared across register/login/me so every auth response agrees, instead of
     * only /auth/me knowing this field (which left it undefined right after an
     * in-SPA login until a full page reload re-hydrated from /auth/me).
     */
    private function hasEnglishCourses(User $user): bool
    {
        return $user->instructorProfile?->hasEnglishCourses() ?? false;
    }

    /**
     * Browser clients explicitly request an HttpOnly session. API/mobile
     * clients that omit the header retain the existing Sanctum token response.
     */
    private function establishAuthentication(Request $request, User $user): ?string
    {
        if (strtolower((string) $request->header('X-EMC-Auth-Mode')) !== 'cookie') {
            return $user->createToken('api-token')->plainTextToken;
        }

        if (! $request->hasSession()) {
            abort(409, 'Cookie authentication is not configured for this frontend origin.');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return null;
    }
}
