<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRegistrationRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\RegistrationResource;
use App\Mail\CourseRegistrationConfirmation;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Registration;
use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class RegistrationController extends Controller
{
    public function store(StoreRegistrationRequest $request, PaymentGatewayService $paymentGatewayService): JsonResponse
    {
        $validated = $request->validated();

        try {
            $course = Course::findOrFail($validated['course_id']);

            if ($course->type === 'paid' && empty($validated['payment_provider'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'payment_provider is required for paid courses.',
                ], 422);
            }

            $receiptPath = null;
            if ($request->hasFile('receipt') && $request->file('receipt')->isValid()) {
                $receiptPath = $request->file('receipt')->store('receipts', 'public');
            }

            $result = DB::transaction(function () use ($validated, $course, $paymentGatewayService, $receiptPath) {
                $registration = Registration::create([
                    'course_id' => $validated['course_id'],
                    'full_name' => $validated['full_name'],
                    'phone'     => $validated['phone'],
                    'email'     => $validated['email'],
                    'city'      => $validated['city']   ?? null,
                    'gender'    => $validated['gender'] ?? null,
                    'notes'     => $validated['notes']  ?? null,
                    'status'    => $course->type === 'paid' ? 'Pending Payment' : 'Registered',
                ]);

                if ($course->type !== 'paid') {
                    return ['registration' => $registration, 'payment' => null];
                }

                if (($validated['payment_provider'] ?? null) === 'fake') {
                    $payment = Payment::create([
                        'registration_id'     => $registration->id,
                        'amount'              => $course->price,
                        'provider'            => 'fake',
                        'provider_payment_id' => null,
                        'receipt_url'         => $receiptPath,
                        'status'              => 'Pending Payment',
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
                    'status'              => 'Pending Payment',
                ]);

                return ['registration' => $registration, 'payment' => $payment];
            });

            if ($course->type === 'paid') {
                $payment = $result['payment'];
                $registration = $result['registration']->loadMissing([
                    'course.track',
                    'course.instructor',
                    'course.features',
                    'payments',
                ]);

                return response()->json([
                    'success'        => true,
                    'type'           => 'paid',
                    'message'        => 'Payment required.',
                    'registration' => new RegistrationResource($registration),
                    'payment'        => new PaymentResource($payment),
                    'checkout_url'   => $payment->checkout_url,
                ], 201);
            }

            $freeRegistration = $result['registration'];
            $freeRegistration->load('course.track', 'course.instructor', 'course.features');

            try {
                Mail::to($freeRegistration->email)
                    ->send(new CourseRegistrationConfirmation($freeRegistration));
            } catch (Throwable $exception) {
                report($exception);
            }

            return response()->json([
                'success'        => true,
                'type'           => 'free',
                'message'        => 'Registration completed successfully.',
                'registration' => new RegistrationResource($freeRegistration),
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process registration at this time.',
            ], 500);
        }
    }
}
