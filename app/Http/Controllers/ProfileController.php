<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    // ─── GET /api/profile ────────────────────────────────────────────────────

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'user'    => $this->userShape($request->user()),
        ]);
    }

    // ─── PUT /api/profile ────────────────────────────────────────────────────

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'                      => 'sometimes|string|max:255',
            'email'                     => [
                'sometimes', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'phone'                     => 'sometimes|nullable|string|max:30',
            'city'                      => 'sometimes|nullable|string|max:100',
            'country'                   => 'sometimes|nullable|string|max:100',
            'how_did_you_hear_about_us' => 'sometimes|nullable|string|max:255',
        ]);

        // how_did_you_hear_about_us: only allow setting once
        if (isset($data['how_did_you_hear_about_us']) && $user->how_did_you_hear_about_us !== null) {
            unset($data['how_did_you_hear_about_us']);
        }

        $emailChanged = isset($data['email']) && $data['email'] !== $user->email;
        if ($emailChanged) {
            // Clear verification — user must re-verify the new address
            $data['email_verified_at'] = null;
        }

        $oldSnap = [
            'name'    => $user->name,
            'email'   => $user->email,
            'phone'   => $user->phone,
            'city'    => $user->city,
            'country' => $user->country,
        ];

        $user->update($data);
        $fresh = $user->fresh();

        AuditLogService::log('updated', 'User', $user->id, $fresh->name, $oldSnap, [
            'name'    => $fresh->name,
            'email'   => $fresh->email,
            'phone'   => $fresh->phone,
            'city'    => $fresh->city,
            'country' => $fresh->country,
        ], ['self_profile_update' => true, 'email_changed' => $emailChanged], $request);

        return response()->json([
            'success'       => true,
            'message'       => $emailChanged
                ? 'Profile updated. Email changed — please verify your new address.'
                : 'Profile updated successfully.',
            'email_changed' => $emailChanged,
            'user'          => $this->userShape($fresh),
        ]);
    }

    // ─── POST /api/profile/avatar ─────────────────────────────────────────────

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'], // 3 MB
        ]);

        $user = $request->user();

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $url  = Storage::disk('public')->url($path);

        $user->update(['avatar' => $path]);

        return response()->json([
            'success'           => true,
            'message'           => 'Profile photo uploaded successfully.',
            'profile_photo_url' => $url,
            'avatar_url'        => $url, // backward-compat alias
            'user'              => $this->userShape($user->fresh()),
        ]);
    }

    // ─── DELETE /api/profile ──────────────────────────────────────────────────

    /**
     * GDPR-style self-deletion.
     *
     * Rules:
     * - super_admin can only delete own account if at least one other super_admin exists
     * - PII is anonymized before deletion (name, email, phone, avatar, city, country)
     * - Payment/audit/legal records are retained (they no longer reference personal data)
     * - Tokens revoked, avatar deleted from storage
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        // Guard: super_admin cannot delete if they are the last one
        if ($user->isSuperAdmin()) {
            $otherSuperAdmins = User::where('role', 'super_admin')
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherSuperAdmins === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete the only super_admin account. Assign another super_admin first.',
                ], 422);
            }
        }

        // Anonymize PII before hard delete (retains audit/payment foreign keys as null)
        $anonymizedEmail = 'deleted_' . $user->id . '_' . time() . '@emc.deleted';

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->tokens()->delete();

        $user->update([
            'name'                      => 'Deleted User',
            'email'                     => $anonymizedEmail,
            'phone'                     => null,
            'avatar'                    => null,
            'city'                      => null,
            'country'                   => null,
            'how_did_you_hear_about_us' => null,
            'password'                  => Hash::make(\Illuminate\Support\Str::random(64)),
            'is_active'                 => false,
        ]);

        AuditLogService::log('deleted', 'User', $user->id, 'self-deleted user #' . $user->id,
            ['role' => $user->role], null, ['self_deletion' => true], $request);

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Your account has been deleted. Personal data has been anonymized.',
        ]);
    }

    // ─── Shared response shape ────────────────────────────────────────────────

    private function userShape(\App\Models\User $user): array
    {
        return [
            'id'                        => $user->id,
            'name'                      => $user->name,
            'email'                     => $user->email,
            'phone'                     => $user->phone,
            'city'                      => $user->city,
            'country'                   => $user->country,
            'role'                      => $user->role,
            'department'                => $user->department,
            'is_active'                 => $user->is_active ?? true,
            'profile_photo_url'         => $user->avatar_url,
            'avatar_url'                => $user->avatar_url,
            'how_did_you_hear_about_us' => $user->how_did_you_hear_about_us,
            'email_verified_at'         => $user->email_verified_at,
            'last_login_at'             => $user->last_login_at,
            'created_at'                => $user->created_at,
            'updated_at'                => $user->updated_at,
        ];
    }
}
