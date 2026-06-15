<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Manage students enrolled in a specific course.
 *
 * GET    /api/admin/courses/{course}/students          — list participants
 * POST   /api/admin/courses/{course}/students          — manually enroll
 * DELETE /api/admin/courses/{course}/students/{user}   — cancel enrollment
 */
class AdminCourseStudentsController extends Controller
{
    /** Statuses that represent an active (or confirmable) enrollment. */
    private const ACTIVE = [
        RegistrationStatus::REGISTERED->value,
        RegistrationStatus::PAYMENT_CONFIRMED->value,
        RegistrationStatus::ATTENDED->value,
        'confirmed',
        'active',
        'enrolled',
    ];

    // ─── GET /api/admin/courses/{course}/students ─────────────────────────────

    public function index(Course $course, Request $request): JsonResponse
    {
        $query = Registration::with(['user'])
            ->where('course_id', $course->id)
            ->whereNotIn('status', [RegistrationStatus::CANCELLED->value]);

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('full_name', 'like', "%{$term}%")
                  ->orWhere('email',    'like', "%{$term}%")
                  ->orWhere('phone',    'like', "%{$term}%")
                  ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage       = min((int) $request->query('per_page', 50), 200);
        $registrations = $query->latest()->paginate($perPage);

        // Batch-load progress for all linked users
        $userIds     = $registrations->pluck('user_id')->filter()->values()->all();
        $progressMap = StudentProgress::where('course_id', $course->id)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        return response()->json([
            'success' => true,
            'data'    => $registrations->map(fn ($r) => $this->formatParticipant($r, $progressMap)),
            'meta'    => [
                'total'        => $registrations->total(),
                'current_page' => $registrations->currentPage(),
                'last_page'    => $registrations->lastPage(),
                'per_page'     => $registrations->perPage(),
            ],
        ]);
    }

    // ─── POST /api/admin/courses/{course}/students ────────────────────────────

    public function store(Request $request, Course $course): JsonResponse
    {
        $data = $request->validate([
            'user_id'   => 'nullable|integer|exists:users,id',
            'email'     => 'nullable|email|max:255',
            'full_name' => 'nullable|string|max:255',
            'phone'     => 'nullable|string|max:30',
            'status'    => 'nullable|in:registered,confirmed,active,enrolled,payment_confirmed',
        ]);

        // Resolve or create user
        $user = $this->resolveUser($data);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'user_id أو email + full_name مطلوب لإضافة الطالب.',
            ], 422);
        }

        // Promote to student if no privileged role
        if (! in_array($user->role, ['instructor', 'teacher', 'admin', 'super_admin', 'executive_admin', 'student'], true)) {
            $user->update(['role' => 'student']);
        }

        $enrollStatus = $data['status'] ?? RegistrationStatus::REGISTERED->value;

        // Check existing registration
        $existing = Registration::where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            if ($existing->status === RegistrationStatus::CANCELLED->value) {
                // Re-enroll
                $existing->update(['status' => $enrollStatus]);
                $registration = $existing->fresh(['user']);
            } else {
                $progressMap = StudentProgress::where('course_id', $course->id)
                    ->where('user_id', $user->id)
                    ->get()->keyBy('user_id');

                return response()->json([
                    'success' => false,
                    'message' => 'الطالب مسجل في هذه الدورة بالفعل.',
                    'data'    => $this->formatParticipant($existing->load('user'), $progressMap),
                ], 409);
            }
        } else {
            $registration = Registration::create([
                'course_id' => $course->id,
                'user_id'   => $user->id,
                'full_name' => $user->name,
                'email'     => $user->email,
                'phone'     => $user->phone ?? ($data['phone'] ?? null),
                'status'    => $enrollStatus,
            ]);
            $registration->load('user');
        }

        // Ensure student_progress record — total_sessions must never be null
        $totalSessions = $course->sessions()->count();
        $progress = StudentProgress::updateOrCreate(
            ['user_id' => $user->id, 'course_id' => $course->id],
            [
                'progress_percentage'              => 0,
                'status'                           => 'in_progress',
                'total_sessions'                   => $totalSessions,
                'completed_sessions'               => 0,
                'attendance_percentage'            => 0,
                'assignment_completion_percentage' => 0,
            ]
        );

        AuditLogService::log(
            'student_enrolled',
            'Course', $course->id, $course->title,
            [],
            ['user_id' => $user->id, 'email' => $user->email, 'status' => $enrollStatus],
            null,
            $request
        );

        $progressMap = collect([$user->id => $progress]);

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة الطالب للدورة بنجاح.',
            'data'    => $this->formatParticipant($registration, $progressMap),
        ], 201);
    }

    // ─── DELETE /api/admin/courses/{course}/students/{user} ──────────────────

    public function destroy(Request $request, Course $course, User $user): JsonResponse
    {
        $registration = Registration::where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->whereNotIn('status', [RegistrationStatus::CANCELLED->value])
            ->first();

        if (! $registration) {
            return response()->json([
                'success' => false,
                'message' => 'التسجيل غير موجود أو ملغى بالفعل.',
            ], 404);
        }

        $registration->update(['status' => RegistrationStatus::CANCELLED->value]);

        // Mark progress as cancelled — preserve history
        StudentProgress::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->update(['status' => 'cancelled']);

        AuditLogService::log(
            'student_unenrolled',
            'Course', $course->id, $course->title,
            ['user_id' => $user->id, 'status' => 'registered'],
            ['status' => 'cancelled'],
            null,
            $request
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء تسجيل الطالب من الدورة. السجل محفوظ.',
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function resolveUser(array $data): ?User
    {
        if (! empty($data['user_id'])) {
            return User::find($data['user_id']);
        }

        if (! empty($data['email'])) {
            $user = User::where('email', $data['email'])->first();

            if ($user) {
                return $user;
            }

            // Create minimal student account from email + name
            if (! empty($data['full_name'])) {
                return User::create([
                    'name'      => $data['full_name'],
                    'email'     => $data['email'],
                    'password'  => Hash::make(Str::random(16)),
                    'role'      => 'student',
                    'phone'     => $data['phone'] ?? null,
                    'is_active' => true,
                ]);
            }
        }

        return null;
    }

    private function formatParticipant(Registration $reg, $progressMap): array
    {
        $user     = $reg->user;
        $progress = $user ? $progressMap->get($user->id) : null;

        return [
            'registration_id' => $reg->id,
            'status'          => $reg->status,
            'registered_at'   => $reg->created_at,
            'user_id'         => $user?->id,
            'name'            => $user?->name ?? $reg->full_name,
            'email'           => $user?->email ?? $reg->email,
            'phone'           => $user?->phone ?? $reg->phone,
            'has_account'     => $user !== null,
            'avatar_url'      => $user?->avatar_url ?? null,
            'progress_status' => $progress?->status,
            'progress_pct'    => (int) ($progress?->progress_percentage ?? 0),
        ];
    }
}
