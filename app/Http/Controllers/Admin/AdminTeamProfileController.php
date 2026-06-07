<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TeamProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminTeamProfileController extends Controller
{
    /**
     * POST /api/admin/team-profiles/{teamProfile}/create-user-account
     *
     * Three outcomes:
     *  A) already_linked  — profile already has user_id → 200, no password
     *  B) linked_existing — email matches an existing user → link it, 200, no password
     *  C) created         — new user provisioned → 201, temporary_password shown once
     *
     * Response shape (all three cases):
     *  {
     *    success: true,
     *    message: "...",
     *    data: {
     *      member:             { id, name, email, user_id, has_user_account }
     *      user:               { id, name, email, role }
     *      temporary_password: string|null   ← non-null only on case C
     *      password_shown_once: bool
     *      already_linked:     bool
     *      linked_existing:    bool
     *      created:            bool
     *    }
     *  }
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
            $teamProfile->load('user:id,name,email,role');
            return response()->json([
                'success' => true,
                'message' => 'هذا العضو لديه حساب مرتبط بالفعل.',
                'data'    => [
                    'member'             => $this->memberSnapshot($teamProfile),
                    'user'               => $this->userSnapshot($teamProfile->user),
                    'temporary_password' => null,
                    'password_shown_once'=> false,
                    'already_linked'     => true,
                    'linked_existing'    => false,
                    'created'            => false,
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
                    'member'             => $this->memberSnapshot($teamProfile),
                    'user'               => $this->userSnapshot($existingUser),
                    'temporary_password' => null,
                    'password_shown_once'=> false,
                    'already_linked'     => false,
                    'linked_existing'    => true,
                    'created'            => false,
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
                'member'             => $this->memberSnapshot($teamProfile),
                'user'               => $this->userSnapshot($user),
                // Plaintext shown ONCE — only the bcrypt hash is stored.
                'temporary_password' => $temporaryPassword,
                'password_shown_once'=> true,
                'already_linked'     => false,
                'linked_existing'    => false,
                'created'            => true,
            ],
        ], 201);
    }

    private function memberSnapshot(TeamProfile $m): array
    {
        return [
            'id'              => $m->id,
            'name'            => $m->name_ar,
            'email'           => $m->email,
            'user_id'         => $m->user_id,
            'has_user_account'=> $m->user_id !== null,
        ];
    }

    private function userSnapshot(?User $u): ?array
    {
        if (! $u) return null;
        return [
            'id'    => $u->id,
            'name'  => $u->name,
            'email' => $u->email,
            'role'  => $u->role,
        ];
    }
}
