<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Models\Course;
use App\Models\ManualPayment;
use App\Models\OralAssessmentBooking;
use App\Models\PaymentReconciliation;
use App\Models\PlacementTestAttempt;
use App\Models\Registration;
use App\Models\User;

/**
 * Production hotfix — canonical, single-source-of-truth answer to "can this
 * student access this paid course / start its placement test right now?".
 *
 * Payment truth lives on `payments.status` (real values, confirmed from
 * CheckoutController/StripeWebhookController/RegistrationController:
 * `pending_payment`, `payment_confirmed`, `payment_failed` — the same
 * vocabulary as RegistrationStatus, not a separate enum). A registration
 * being non-cancelled is NOT proof of payment — RegistrationStatus's own
 * `activeEnrollment()` allowlist (registered/payment_confirmed/attended)
 * already encodes that distinction correctly and is reused here verbatim,
 * never re-derived.
 *
 * Decision order (payment before placement, per the mandated business rule):
 *   1. Is there a valid (active-enrollment) registration, or a learning-path
 *      enrollment that grants direct bypass?
 *   2. Is the course paid — if so, is payment actually confirmed?
 *   3. Does the course require a placement test — if so, is it complete?
 *   4. Grant learning access.
 */
class CourseAccessEligibilityService
{
    public function __construct(private readonly PlacementProgressService $placement) {}

    /**
     * @return array{
     *   is_paid_course: bool,
     *   payment_required: bool,
     *   payment_status: string|null,
     *   payment_completed: bool,
     *   payment_under_review: bool,
     *   payment_url: string|null,
     *   enrollment_active: bool,
     *   can_start_placement_test: bool,
     *   placement_test_required: bool,
     *   can_access_learning: bool,
     *   block_reason: string,
     *   registration_id: int|null,
     * }
     */
    public function forUserAndCourse(User $user, Course $course): array
    {
        $isPaidCourse = $course->type === 'paid' || (bool) ($course->is_paid ?? false);

        // Learning-path enrollment bypasses the direct-registration/payment
        // gate entirely — the path itself is the paid/granted unit, mirrors
        // PlacementTestRequired's pre-existing bypass and StudentLearnController's
        // fallback check.
        $viaLearningPath = Registration::forStudent($user)
            ->where('course_id', $course->id)
            ->whereNotNull('learning_path_id')
            ->activeEnrollment()
            ->exists();

        // Latest registration regardless of status — needed to distinguish
        // "never registered" from "registered but payment pending/failed"
        // for an honest block_reason and to surface the real payment_url.
        $registration = Registration::forStudent($user)
            ->where('course_id', $course->id)
            ->with('payments')
            ->latest()
            ->first();

        $enrollmentActive = $viaLearningPath || (
            $registration !== null
            && in_array($registration->status, RegistrationStatus::activeEnrollment(), true)
        );

        $lastPayment = $registration?->payments?->last();

        // Money received, enrollment withheld for a financial reconciliation (the payer teaches what they paid for): NOT a payment
        // to complete, and never a reason to pay again. See PaymentReconciliation.
        $underReview = ! $enrollmentActive && $registration !== null
            && PaymentReconciliation::query()->where('registration_id', $registration->id)->where('status', PaymentReconciliation::STATUS_OPEN)->exists();

        // A manual payment submitted for admin review (bank transfer, etc.) has
        // no Payment row yet — ManualPaymentConfirmationService only creates one
        // once an admin confirms it (see ManualPaymentController::confirm()).
        // Until then the real, most current truth is the ManualPayment's own
        // 'pending_review' status (an existing value in that domain, not a new
        // one invented here), so the student sees "under review" rather than a
        // generic "payment pending" while their proof is actually being checked.
        $pendingManualPayment = $isPaidCourse
            ? ManualPayment::where('student_id', $user->id)
                ->where('purchasable_type', Course::class)
                ->where('purchasable_id', $course->id)
                ->where('status', 'pending_review')
                ->latest()
                ->first()
            : null;

        $paymentStatus = $pendingManualPayment?->status
            ?? $lastPayment?->status
            ?? (! $isPaidCourse ? 'free' : null);

        $paymentCompleted = ! $isPaidCourse || $viaLearningPath || $enrollmentActive;

        if (! $enrollmentActive && ! $viaLearningPath) {
            $blockReason = match (true) {
                $underReview => 'payment_under_review',
                $registration === null => 'no_registration',
                $paymentStatus === RegistrationStatus::PENDING_PAYMENT->value => 'payment_pending',
                $paymentStatus === 'pending_review' => 'payment_pending',
                $paymentStatus === 'payment_failed' => 'payment_failed',
                $registration->status === RegistrationStatus::CANCELLED->value => 'registration_cancelled',
                default => 'payment_required',
            };

            return [
                'is_paid_course'           => $isPaidCourse,
                'payment_required'         => $isPaidCourse && ! $underReview,
                'payment_status'           => $paymentStatus,
                'payment_completed'        => $underReview,
                'payment_under_review'     => $underReview,
                'payment_url'              => $isPaidCourse && ! $underReview ? $lastPayment?->checkout_url : null,
                'enrollment_active'        => false,
                'can_start_placement_test' => false,
                'placement_test_required'  => (bool) $course->requires_placement_test,
                'can_access_learning'      => false,
                'block_reason'             => $blockReason,
                'registration_id'          => $registration?->id,
            ];
        }

        // Enrollment (and, for paid courses, payment) is confirmed — now, and
        // only now, does placement-test state get evaluated. Payment is
        // always decided before placement, per the mandated decision order.
        $placementRequired = (bool) $course->requires_placement_test;

        if (! $placementRequired || $viaLearningPath) {
            return [
                'is_paid_course'           => $isPaidCourse,
                'payment_required'         => $isPaidCourse,
                'payment_status'           => $paymentStatus,
                'payment_completed'        => $paymentCompleted,
                'payment_under_review'     => false,
                'payment_url'              => null,
                'enrollment_active'        => true,
                'can_start_placement_test' => false,
                'placement_test_required'  => $placementRequired && ! $viaLearningPath,
                'can_access_learning'      => true,
                'block_reason'             => 'access_allowed',
                'registration_id'          => $registration?->id,
            ];
        }

        $attempt = PlacementTestAttempt::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->latest()
            ->first();
        $booking = $attempt ? OralAssessmentBooking::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->latest()
            ->first() : null;

        $status = $this->placement->deriveStatus($attempt, $booking);
        $canAccessLearning = $status === PlacementProgressService::STATUS_COMPLETED;

        $blockReason = match (true) {
            $canAccessLearning => 'access_allowed',
            in_array($status, [PlacementProgressService::STATUS_NOT_STARTED, PlacementProgressService::STATUS_IN_PROGRESS], true) => 'placement_test_required',
            $status === PlacementProgressService::STATUS_WRITTEN_COMPLETED => 'placement_test_in_progress',
            default => 'placement_test_in_progress',
        };

        return [
            'is_paid_course'           => $isPaidCourse,
            'payment_required'         => $isPaidCourse,
            'payment_status'           => $paymentStatus,
            'payment_completed'        => $paymentCompleted,
            'payment_under_review'     => false,
            'payment_url'              => null,
            'enrollment_active'        => true,
            'can_start_placement_test' => in_array($status, [
                PlacementProgressService::STATUS_NOT_STARTED,
                PlacementProgressService::STATUS_IN_PROGRESS,
            ], true),
            'placement_test_required'  => true,
            'can_access_learning'      => $canAccessLearning,
            'block_reason'             => $blockReason,
            'registration_id'          => $registration?->id,
        ];
    }
}
