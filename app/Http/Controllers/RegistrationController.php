<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Events\StudentRegisteredEvent;
use App\Http\Requests\StoreRegistrationRequest;
use App\Http\Resources\CourseResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\RegistrationResource;
use App\Mail\CourseRegistrationConfirmation;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Services\PaymentGatewayService;
use App\Support\CourseComputedStatus;
use App\Support\PaymentStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Exception\ApiErrorException as StripeApiException;
use Throwable;

class RegistrationController extends Controller
{
    /**
     * POST /api/courses/{course}/register — same as store; course id from URL.
     */
    public function storeForCourse(
        StoreRegistrationRequest $request,
        Course $course,
        PaymentGatewayService $paymentGatewayService,
    ): JsonResponse {
        return $this->store($request, $paymentGatewayService);
    }

    public function store(StoreRegistrationRequest $request, PaymentGatewayService $paymentGatewayService): JsonResponse
    {
        $validated = $request->validated();
        $authUser  = $request->user(); // null for public (unauthenticated) submissions

        try {
            $course = Course::findOrFail($validated['course_id']);
            $course->loadMissing(['track', 'instructor.user', 'features']);

            // Ended courses: block public enrollment unless admin keeps registration_open (override).
            if (CourseComputedStatus::blocksPublicEnrollment($course)) {
                return response()->json([
                    'success'    => false,
                    'message'    => 'This course has ended and enrollment is no longer available.',
                    'message_ar' => 'انتهت هذه الدورة ولا يمكن التسجيل فيها حالياً.',
                ], 422);
            }

            // Path-owned courses can only be reached via Learning Path enrollment
            // (see StudentLearningPathController::enroll, which creates Registration rows
            // directly and never goes through this controller).
            if ($course->activeLearningPath()) {
                return response()->json([
                    'message'    => 'This course is part of a learning path and cannot be enrolled directly.',
                    'message_ar' => 'هذه الدورة جزء من مسار تعليمي ولا يمكن التسجيل فيها مباشرة. يرجى التسجيل في المسار التعليمي.',
                ], 422);
            }

            if ($course->requires_registration_code) {
                $submitted = trim((string) ($validated['registration_code'] ?? ''));
                $stored    = trim((string) ($course->registration_code ?? ''));
                if ($submitted === '' || $stored === '' || strcasecmp($submitted, $stored) !== 0) {
                    return response()->json([
                        'success'    => false,
                        'message'    => 'Invalid or missing registration code for this course.',
                        'message_ar' => 'رمز التسجيل غير صحيح أو مطلوب لهذه الدورة.',
                    ], 422);
                }
            }

            $courseIsPaid = $course->type === 'paid' || (bool) ($course->is_paid ?? false);

            if ($courseIsPaid && empty($validated['payment_provider'])) {
                // New checkout flow: client should use POST /courses/{id}/checkout instead
                return response()->json([
                    'success'      => false,
                    'message'      => 'يرجى استخدام نقطة نهاية الدفع لهذه الدورة.',
                    'requires_checkout' => true,
                    'checkout_endpoint' => "/api/courses/{$course->id}/checkout",
                ], 402);
            }

            $emailNorm = mb_strtolower(trim((string) (($authUser?->email) ?? $validated['email'])));

            // One active registration per course per identity (user_id or normalized email)
            $existing = Registration::query()
                ->where('course_id', $course->id)
                ->whereNotIn('status', [RegistrationStatus::CANCELLED->value])
                ->where(function ($q) use ($authUser, $emailNorm) {
                    if ($authUser) {
                        $q->where('user_id', $authUser->id)
                            ->orWhereRaw('LOWER(TRIM(email)) = ?', [$emailNorm]);
                    } else {
                        $q->whereRaw('LOWER(TRIM(email)) = ?', [$emailNorm]);
                    }
                })
                ->first();

            if ($existing) {
                return response()->json([
                    'success'        => false,
                    'message'        => 'أنت مسجل بالفعل في هذه الدورة',
                    'registration'   => new RegistrationResource($existing->loadMissing('course', 'payments')),
                ], 409);
            }

            $receiptPath = null;
            if ($request->hasFile('receipt') && $request->file('receipt')->isValid()) {
                $receiptPath = $request->file('receipt')->store('receipts', 'public');
            }

            $result = DB::transaction(function () use ($validated, $course, $paymentGatewayService, $receiptPath, $authUser, $courseIsPaid) {
                $registration = Registration::create([
                    'course_id'          => $validated['course_id'],
                    'user_id'            => $authUser?->id,
                    'full_name'          => $authUser?->name ?? $validated['full_name'],
                    'phone'              => $validated['phone'] ?? $authUser?->phone,
                    'email'              => $authUser?->email ?? $validated['email'],
                    'city'               => $validated['city'] ?? $authUser?->city,
                    'country'            => $validated['country'] ?? $authUser?->country,
                    'country_code'       => $validated['country_code'] ?? null,
                    'phone_country_code' => $validated['phone_country_code'] ?? null,
                    'gender'             => $validated['gender'] ?? null,
                    'notes'              => $validated['notes'] ?? null,
                    'status'             => $courseIsPaid ? RegistrationStatus::PENDING_PAYMENT->value : RegistrationStatus::REGISTERED->value,
                ]);

                if (! $courseIsPaid) {
                    // Ensure student_progress exists for authenticated users
                    if ($authUser) {
                        StudentProgress::updateOrCreate(
                            ['user_id' => $authUser->id, 'course_id' => $course->id],
                            [
                                'progress_percentage'              => 0,
                                'status'                           => 'in_progress',
                                'total_sessions'                   => $course->sessions()->count(),
                                'completed_sessions'               => 0,
                                'attendance_percentage'            => 0,
                                'assignment_completion_percentage' => 0,
                            ]
                        );
                    }
                    return ['registration' => $registration, 'payment' => null];
                }

                if (($validated['payment_provider'] ?? null) === 'fake') {
                    $payment = Payment::create([
                        'registration_id'     => $registration->id,
                        'amount'              => $course->price,
                        'provider'            => 'fake',
                        'provider_payment_id' => null,
                        'receipt_url'         => $receiptPath,
                        'status'              => PaymentStatuses::PENDING,
                    ]);

                    $checkoutUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/fake-payment/' . $payment->id;
                    $payment->update([
                        'provider_payment_id' => (string) $payment->id,
                        'checkout_url'        => $checkoutUrl,
                    ]);

                    return ['registration' => $registration, 'payment' => $payment->fresh()];
                }

                $gatewayPayment = $paymentGatewayService->createCoursePayment(
                    $registration,
                    $course,
                    $validated['payment_provider']
                );

                $payment = Payment::create([
                    'registration_id'     => $registration->id,
                    'amount'              => $course->price,
                    'provider'            => $gatewayPayment['provider'],
                    'provider_payment_id' => $gatewayPayment['provider_payment_id'],
                    'checkout_url'        => $gatewayPayment['checkout_url'],
                    'receipt_url'         => $receiptPath,
                    'status'              => PaymentStatuses::PENDING,
                ]);

                return ['registration' => $registration, 'payment' => $payment];
            });

            if ($courseIsPaid) {
                $payment = $result['payment'];
                $registration = $result['registration']->loadMissing([
                    'course.track',
                    'course.instructor',
                    'course.features',
                    'payments',
                ]);

                AuditLogService::log('created', 'Registration', $registration->id,
                    "{$registration->full_name} → {$course->title}",
                    null,
                    ['status' => $registration->status, 'course_id' => $course->id, 'user_id' => $authUser?->id],
                    null, $request
                );

                if ($authUser) {
                    // Authenticated: fire event — listener handles both student + admin notifications
                    try {
                        $registration->loadMissing('course');
                        event(new StudentRegisteredEvent($registration));
                    } catch (Throwable $e) {
                        report($e);
                    }
                } else {
                    // Guest: no user_id — notify admins directly (studentUserId=0 filtered safely)
                    try {
                        NotificationService::studentRegistered(0, $course->id, $course->title, $registration->id);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }

                return response()->json([
                    'success'        => true,
                    'type'           => 'paid',
                    'message'        => 'تم بدء التسجيل — أكمل الدفع لإتمام طلبك.',
                    'registration'   => new RegistrationResource($registration),
                    'course'         => new CourseResource($course),
                    'payment'        => new PaymentResource($payment),
                    'checkout_url'   => $payment->checkout_url,
                ], 201);
            }

            $freeRegistration = $result['registration'];
            $freeRegistration->load('course.track', 'course.instructor', 'course.features');

            AuditLogService::log('created', 'Registration', $freeRegistration->id,
                "{$freeRegistration->full_name} → {$course->title}",
                null,
                ['status' => $freeRegistration->status, 'course_id' => $course->id, 'user_id' => $authUser?->id],
                null, $request
            );

            // Confirmation email (always — guest and authenticated)
            Mail::to($freeRegistration->email)
                ->queue(new CourseRegistrationConfirmation($freeRegistration));

            if ($authUser) {
                // Authenticated: fire event — listener handles both student + admin notifications
                try {
                    event(new StudentRegisteredEvent($freeRegistration));
                } catch (Throwable $e) {
                    report($e);
                }
            } else {
                // Guest: no user_id — notify admins directly (studentUserId=0 filtered safely)
                try {
                    NotificationService::studentRegistered(0, $course->id, $course->title, $freeRegistration->id);
                } catch (Throwable $e) {
                    report($e);
                }
            }

            return response()->json([
                'success'        => true,
                'type'           => 'free',
                'message'        => 'تم التسجيل بنجاح',
                'registration'   => new RegistrationResource($freeRegistration),
                'course'         => new CourseResource($course),
            ], 201);
        } catch (StripeApiException $exception) {
            Log::error('Stripe API error during registration', [
                'course_id' => $validated['course_id'] ?? null,
                'message'   => $exception->getMessage(),
            ]);

            return response()->json([
                'success'    => false,
                'message'    => 'تعذّر الاتصال ببوابة الدفع. تحقق من إعدادات Stripe أو حاول مرة أخرى.',
                'message_en' => 'Payment gateway error: ' . $exception->getMessage(),
            ], 502);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'تعذّر معالجة طلبك في الوقت الحالي. حاول مرة أخرى.',
            ], 500);
        }
    }
}
