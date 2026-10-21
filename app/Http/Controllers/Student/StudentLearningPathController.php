<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\LearningPathResource;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\PaymentReconciliation;
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

    /** The payer's money for this path arrived but the enrollment is withheld for a financial review (they teach it). */
    private function paymentUnderReview(int $userId, int $pathId): bool
    {
        return PaymentReconciliation::query()
            ->where('user_id', $userId)
            ->where('learning_path_id', $pathId)
            ->where('status', PaymentReconciliation::STATUS_OPEN)
            ->exists();
    }

    private function underReviewResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'enrolled' => false,
            'requires_checkout' => false,
            'payment_under_review' => true,
            'code' => 'payment_under_review',
            'message' => 'تم استلام دفعتك وهي قيد المراجعة المالية. لا حاجة لدفع مرة أخرى، وسنتواصل معك.',
        ], 409);
    }

    // ─── POST /api/learning-paths/{slug}/enroll ───────────────────────────────
    public function enroll(Request $request, string $slug): JsonResponse
    {
        $path = LearningPath::where('slug', $slug)
            ->where('status', 'published')
            ->where('enrollment_open', true)
            ->firstOrFail();

        $userId = Auth::id();

        // A payment already received and under financial review is answered first: the person paid, so they are neither told off
        // (the instructor conflict below) nor asked to pay again.
        if ($this->paymentUnderReview((int) $userId, (int) $path->id)) {
            return $this->underReviewResponse();
        }

        // Learning is open to every account; teaching this path (or a course in it) is the one thing that rules it out.
        app(\App\Services\LearnerEnrollmentGuard::class)->assertCanEnrollInPath(Auth::user(), $path);

        $existing = LearningPathEnrollment::where('learning_path_id', $path->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            if ($existing->status === 'pending_payment') {
                // Paid and under review: never "complete the payment" again.
                if ($this->paymentUnderReview((int) $userId, (int) $path->id)) {
                    return $this->underReviewResponse();
                }

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

        $underReview = PaymentReconciliation::query()
            ->where('user_id', $userId)
            ->where('status', PaymentReconciliation::STATUS_OPEN)
            ->whereNotNull('learning_path_id')
            ->pluck('learning_path_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $data = $enrollments->map(function (LearningPathEnrollment $e) use ($underReview) {
            $path = $e->learningPath;
            return [
                'enrollment_id'  => $e->id,
                'enrollment_status' => $e->status,
                'payment_under_review' => $e->status === 'pending_payment' && in_array((int) $e->learning_path_id, $underReview, true),
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
            if ($this->paymentUnderReview((int) $userId, (int) $enrollment->learning_path_id)) {
                return $this->underReviewResponse();
            }

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
        $underReview = $enrollment?->status === 'pending_payment' && $this->paymentUnderReview((int) $userId, (int) $path->id);

        return response()->json([
            'success'  => true,
            'enrolled' => (bool) $isActive,
            'payment_under_review' => $underReview,
            'requires_checkout' => $enrollment?->status === 'pending_payment' && ! $underReview,
            'checkout_url' => $underReview ? null : $latestPayment?->checkout_url,
            'enrollment' => $enrollment ? [
                'id'          => $enrollment->id,
                'status'      => $enrollment->status,
                'enrolled_at' => $enrollment->enrolled_at,
                'completed_at' => $enrollment->completed_at,
            ] : null,
        ]);
    }
}
