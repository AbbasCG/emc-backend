<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage Sanctum personal access tokens for external API access.
 *
 * Tokens are scoped (e.g. read:registrations, write:webhooks) using
 * Sanctum's abilities feature.
 */
class ApiTokenController extends Controller
{
    public const ALLOWED_SCOPES = [
        'read:registrations',
        'read:courses',
        'read:certificates',
        'read:reports',
        'write:webhooks',
        'write:forms',
    ];

    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()
            ->tokens()
            ->whereNull('last_used_at')
            ->orWhereNotNull('last_used_at')
            ->latest()
            ->get()
            ->map(fn ($token) => [
                'id'           => $token->id,
                'name'         => $token->name,
                'abilities'    => $token->abilities,
                'last_used_at' => $token->last_used_at,
                'created_at'   => $token->created_at,
                'expires_at'   => $token->expires_at,
            ]);

        return response()->json(['success' => true, 'data' => $tokens]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'abilities'  => 'sometimes|array',
            'abilities.*' => 'string|in:' . implode(',', self::ALLOWED_SCOPES),
            'expires_at' => 'sometimes|nullable|date|after:now',
        ]);

        $abilities = $data['abilities'] ?? ['read:courses'];

        $token = $request->user()->createToken(
            $data['name'],
            $abilities,
            isset($data['expires_at']) ? \Carbon\Carbon::parse($data['expires_at']) : null,
        );

        return response()->json([
            'success'    => true,
            'message'    => 'API token created. Save the plain text token — it will not be shown again.',
            'data'       => [
                'id'        => $token->accessToken->id,
                'name'      => $token->accessToken->name,
                'abilities' => $token->accessToken->abilities,
            ],
            'token'      => $token->plainTextToken,
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = $request->user()->tokens()->where('id', $id)->delete();

        if (! $deleted) {
            return response()->json(['success' => false, 'message' => 'Token not found.'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Token revoked.']);
    }
}
