<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    // ─── GET /api/profile ────────────────────────────────────────────────────

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'user'    => UserResource::make($request->user()),
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
            'user'          => UserResource::make($fresh),
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
            'user'              => UserResource::make($user->fresh()),
        ]);
    }

    // ─── DELETE /api/profile ──────────────────────────────────────────────────

    /**
     * Self-deletion: sets is_active=false, revokes tokens, removes avatar, soft-deletes.
     * super_admin cannot delete if they are the only one.
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

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
            $user->update(['avatar' => null]);
        }

        $user->tokens()->delete();

        $user->update(['is_active' => false]);

        AuditLogService::log('deleted', 'User', $user->id, $user->name,
            ['role' => $user->role, 'email' => $user->email], null, ['self_deletion' => true], $request);

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Your account has been deleted.',
        ]);
    }

}
