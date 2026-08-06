<?php

namespace App\Http\Controllers\Student;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LearningPathResource;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudentLearningPathController extends Controller
{
    // ─── POST /api/learning-paths/{slug}/enroll ───────────────────────────────
    public function enroll(Request $request, string $slug): JsonResponse
    {
        $path = LearningPath::where('slug', $slug)
            ->where('status', 'published')
            ->where('enrollment_open', true)
            ->firstOrFail();

        $userId = Auth::id();

        $existing = LearningPathEnrollment::where('learning_path_id', $path->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            return response()->json([
                'success'    => false,
                'enrolled'   => true,
                'message'    => 'أنت مسجل بالفعل في هذا المسار التعليمي.',
                'enrollment' => [
                    'id'          => $existing->id,
                    'status'      => $existing->status,
                    'enrolled_at' => $existing->enrolled_at,
                ],
            ], 409);
        }

        $enrollment = LearningPathEnrollment::create([
            'learning_path_id' => $path->id,
            'user_id'          => $userId,
            'status'           => 'active',
            'enrolled_at'      => now(),
        ]);

        // Auto-register the student into every included course so they get immediate access.
        // ⚠ Note: this grants access to paid courses without payment. If that changes,
        //   gate creation on $course->type !== 'paid'.
        $user = Auth::user();
        $path->load('courses');

        foreach ($path->courses as $course) {
            try {
                $alreadyRegistered = Registration::where('course_id', $course->id)
                    ->where('user_id', $userId)
                    ->whereNotIn('status', [RegistrationStatus::CANCELLED->value])
                    ->exists();

                if (! $alreadyRegistered) {
                    Registration::create([
                        'course_id'        => $course->id,
                        'user_id'          => $userId,
                        'learning_path_id' => $path->id,
                        'full_name'        => $user->name,
                        'email'            => $user->email,
                        'phone'            => $user->phone ?? null,
                        'city'             => $user->city ?? null,
                        'country'          => $user->country ?? null,
                        'status'           => RegistrationStatus::REGISTERED->value,
                    ]);
                }
            } catch (\Throwable) {
                // Per-course failure is non-fatal; path enrollment already succeeded.
            }
        }

        return response()->json([
            'success'    => true,
            'message'    => 'تم التسجيل في المسار التعليمي بنجاح.',
            'enrollment' => [
                'id'          => $enrollment->id,
                'status'      => $enrollment->status,
                'enrolled_at' => $enrollment->enrolled_at,
            ],
        ], 201);
    }

    // ─── GET /api/student/learning-paths ─────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $userId = Auth::id();

        $enrollments = LearningPathEnrollment::with([
            'learningPath.instructor.user',
            'learningPath.courses',
        ])
            ->where('user_id', $userId)
            ->whereHas('learningPath', fn ($q) => $q->where('status', 'published'))
            ->latest('enrolled_at')
            ->get();

        $data = $enrollments->map(function (LearningPathEnrollment $e) {
            $path = $e->learningPath;
            return [
                'enrollment_id'  => $e->id,
                'enrollment_status' => $e->status,
                'enrolled_at'    => $e->enrolled_at,
                'completed_at'   => $e->completed_at,
                'learning_path'  => (new LearningPathResource($path->loadCount('courses')))->toArray(request()),
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ─── GET /api/student/learning-paths/{id} ─────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        $userId = Auth::id();

        $enrollment = LearningPathEnrollment::with([
            'learningPath.instructor.user',
            'learningPath.courses',
            'learningPath.items',
        ])
            ->where('user_id', $userId)
            ->where('learning_path_id', $id)
            ->firstOrFail();

        $path = $enrollment->learningPath;
        $path->loadCount('courses');

        return response()->json([
            'success'        => true,
            'enrollment_id'  => $enrollment->id,
            'enrollment_status' => $enrollment->status,
            'enrolled_at'    => $enrollment->enrolled_at,
            'completed_at'   => $enrollment->completed_at,
            'data'           => new LearningPathResource($path),
        ]);
    }

    // ─── GET /api/learning-paths/{slug}/enrollment-status ────────────────────
    public function enrollmentStatus(string $slug): JsonResponse
    {
        $path = LearningPath::where('slug', $slug)->where('status', 'published')->firstOrFail();

        $userId = Auth::id();

        $enrollment = LearningPathEnrollment::where('learning_path_id', $path->id)
            ->where('user_id', $userId)
            ->first();

        return response()->json([
            'success'  => true,
            'enrolled' => (bool) $enrollment,
            'enrollment' => $enrollment ? [
                'id'          => $enrollment->id,
                'status'      => $enrollment->status,
                'enrolled_at' => $enrollment->enrolled_at,
                'completed_at' => $enrollment->completed_at,
            ] : null,
        ]);
    }
}
