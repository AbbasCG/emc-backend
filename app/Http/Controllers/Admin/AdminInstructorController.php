<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminInstructorController extends Controller
{
    private const INSTRUCTOR_ROLES = ['instructor', 'teacher'];

    // ─── GET /api/admin/instructors ───────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 200);

        // Auto-backfill: create profiles for any instructor-role users that are missing them.
        // Uses whereDoesntHave so only users with no profile row are touched.
        User::whereIn('role', self::INSTRUCTOR_ROLES)
            ->whereDoesntHave('instructorProfile')
            ->select(['id', 'name'])
            ->each(fn (User $u) => self::ensureProfile($u));

        // Query directly from instructors table — id is always present.
        $query = Instructor::with('user')
            ->withCount(['courses', 'workshops'])
            ->whereHas('user', fn ($q) => $q->whereIn('role', self::INSTRUCTOR_ROLES));

        if ($request->filled('search')) {
            $s = (string) $request->query('search');
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhereHas('user', fn ($u) => $u
                    ->where('name', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")
                )
            );
        }

        if ($request->filled('is_active')) {
            $query->whereHas('user', fn ($u) => $u->where('is_active', $request->boolean('is_active')));
        }

        $profiles = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $profiles->map(fn (Instructor $p) => $this->formatInstructor($p)),
            'meta'    => [
                'total'        => $profiles->total(),
                'current_page' => $profiles->currentPage(),
                'last_page'    => $profiles->lastPage(),
                'per_page'     => $profiles->perPage(),
            ],
        ]);
    }

    // ─── POST /api/admin/instructors ──────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|max:255|unique:users,email',
            'password'   => 'nullable|string|min:8',
            'phone'      => 'nullable|string|max:30',
            'title'      => 'nullable|string|max:255',
            'bio'        => 'nullable|string',
            'department' => 'nullable|string|max:255',
            'is_active'  => 'nullable|boolean',
        ]);

        $temporaryPassword = null;
        if (empty($data['password'])) {
            $temporaryPassword = Str::random(12);
            $data['password']  = $temporaryPassword;
        }

        $user = User::create([
            'name'       => $data['name'],
            'email'      => $data['email'],
            'password'   => Hash::make($data['password']),
            'role'       => 'instructor',
            'phone'      => $data['phone'] ?? null,
            'department' => $data['department'] ?? null,
            'is_active'  => $data['is_active'] ?? true,
        ]);

        $profile = self::ensureProfile($user);

        if (! empty($data['title'])) {
            $profile->update(['title' => $data['title']]);
        }
        if (! empty($data['bio'])) {
            $profile->update(['bio' => $data['bio']]);
        }

        $profile->load('user');
        $profile->loadCount(['courses', 'workshops']);

        $response = [
            'success' => true,
            'message' => 'Instructor created successfully',
            'data'    => $this->formatInstructor($profile),
        ];

        if ($temporaryPassword !== null) {
            $response['temporary_password'] = $temporaryPassword;
        }

        return response()->json($response, 201);
    }

    // ─── GET /api/admin/instructors/{user} ────────────────────────────────────
    public function show(User $user): JsonResponse
    {
        if (! in_array($user->role, self::INSTRUCTOR_ROLES, true)) {
            return response()->json(['success' => false, 'message' => 'User is not an instructor.'], 404);
        }

        $profile = self::ensureProfile($user);
        $profile->load('user');
        $profile->loadCount(['courses', 'workshops']);

        return response()->json([
            'success' => true,
            'data'    => $this->formatInstructor($profile),
        ]);
    }

    // ─── Shared helpers ───────────────────────────────────────────────────────

    public static function ensureProfile(User $user): Instructor
    {
        $profile = Instructor::firstOrCreate(
            ['user_id' => $user->id],
            ['name'    => $user->name]
        );

        // Keep profile name in sync with the user's canonical name.
        if ($profile->name !== $user->name) {
            $profile->update(['name' => $user->name]);
        }

        return $profile;
    }

    private function formatInstructor(Instructor $profile): array
    {
        $user       = $profile->relationLoaded('user') ? $profile->user : null;
        $avatarPath = $user?->avatar ?? $profile->image;
        $photoUrl   = $avatarPath
            ? (str_starts_with($avatarPath, 'http')
                ? $avatarPath
                : asset('storage/' . ltrim($avatarPath, '/')))
            : null;

        return [
            'id'                     => $profile->id,
            'user_id'                => $profile->user_id,
            'name'                   => $user?->name ?? $profile->name,
            'email'                  => $user?->email,
            'phone'                  => $user?->phone,
            'role'                   => $user?->role ?? 'instructor',
            'is_active'              => (bool) ($user?->is_active ?? true),
            'title'                  => $profile->title,
            'bio'                    => $profile->bio ?? '',
            'profile_photo_url'      => $photoUrl,
            'image'                  => $photoUrl,
            'tags'                   => [],
            'assigned_courses_count' => $profile->courses_count ?? 0,
            'workshops_count'        => $profile->workshops_count ?? 0,
            'created_at'             => $user?->created_at ?? $profile->created_at,
            'updated_at'             => $user?->updated_at ?? $profile->updated_at,
        ];
    }
}
