<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\LearningPathResource;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Services\LearningPathEnrollmentService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudentLearningPathController extends Controller
{
    public function __construct(private readonly LearningPathEnrollmentService $enrollments)
    {
    }

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
            if ($existing->status === 'pending_payment') {
                return response()->json([
                    'success' => false,
                    'enrolled' => false,
                    'requires_checkout' => true,
                    'checkout_endpoint' => "/api/learning-paths/{$path->slug}/checkout",
                    'message' => 'أكمل الدفع لتفعيل هذا المسار.',
                ], 402);
            }

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

        $user = Auth::user();

        if ($path->isPaid()) {
            return response()->json([
                'success' => false,
                'enrolled' => false,
                'requires_checkout' => true,
                'checkout_endpoint' => "/api/learning-paths/{$path->slug}/checkout",
                'message' => 'هذا مسار مدفوع. أكمل الدفع لتفعيل التسجيل.',
            ], 402);
        }

        $enrollment = $this->enrollments->enrollFree($user, $path);
        $path->loadCount('courses');

        NotificationService::learningPathEnrolled(
            $userId,
            $path->title,
            (int) $path->courses_count,
            $path->id,
            $path->duration,
        );

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

        if ($enrollment->status === 'pending_payment') {
            return response()->json([
                'success' => false,
                'requires_checkout' => true,
                'checkout_endpoint' => "/api/learning-paths/{$enrollment->learningPath->slug}/checkout",
                'message' => 'أكمل الدفع للوصول إلى محتوى المسار.',
            ], 402);
        }

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

        $enrollment = LearningPathEnrollment::with('order.payments')
            ->where('learning_path_id', $path->id)
            ->where('user_id', $userId)
            ->first();

        $isActive = $enrollment && in_array($enrollment->status, ['active', 'completed'], true);
        $latestPayment = $enrollment?->order?->payments?->sortByDesc('id')->first();

        return response()->json([
            'success'  => true,
            'enrolled' => (bool) $isActive,
            'requires_checkout' => $enrollment?->status === 'pending_payment',
            'checkout_url' => $latestPayment?->checkout_url,
            'enrollment' => $enrollment ? [
                'id'          => $enrollment->id,
                'status'      => $enrollment->status,
                'enrolled_at' => $enrollment->enrolled_at,
                'completed_at' => $enrollment->completed_at,
            ] : null,
        ]);
    }
}
