<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Registration;
use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class RegistrationController extends Controller
{
    public function store(Request $request, PaymentGatewayService $paymentGatewayService): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'full_name' => 'required|string|max:255',
            'phone' => 'required|string|max:30',
            'email' => 'required|email|max:255',
            'city' => 'nullable|string|max:255',
            'gender' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:4096',
            'payment_provider' => 'nullable|in:stripe,paypal,fake',
        ]);

        try {
            $course = Course::findOrFail($validated['course_id']);
            if ($course->type === 'paid' && empty($validated['payment_provider'])) {
                return response()->json([
                    'message' => 'payment_provider is required for paid courses.',
                ], 422);
            }
            if (($validated['payment_provider'] ?? null) === 'fake' && ! app()->environment('local')) {
                return response()->json([
                    'message' => 'fake payment_provider is only available in local environment.',
                ], 422);
            }

            $registration = DB::transaction(function () use ($validated, $course, $paymentGatewayService) {
                $registration = Registration::create([
                    'course_id' => $validated['course_id'],
                    'full_name' => $validated['full_name'],
                    'phone' => $validated['phone'],
                    'email' => $validated['email'],
                    'city' => $validated['city'] ?? null,
                    'gender' => $validated['gender'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'status' => $course->type === 'paid' ? 'Pending Payment' : 'Registered',
                ]);

                if ($course->type !== 'paid') {
                    return [
                        'registration' => $registration,
                        'payment' => null,
                    ];
                }

                if (($validated['payment_provider'] ?? null) === 'fake') {
                    $payment = Payment::create([
                        'registration_id' => $registration->id,
                        'amount' => $course->price,
                        'provider' => 'fake',
                        'provider_payment_id' => null,
                        'status' => 'Pending Payment',
                    ]);

                    $checkoutUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/fake-payment/' . $payment->id;
                    $payment->update([
                        'provider_payment_id' => (string) $payment->id,
                        'checkout_url' => $checkoutUrl,
                    ]);

                    return [
                        'registration' => $registration,
                        'payment' => $payment->fresh(),
                    ];
                }

                $gatewayPayment = $paymentGatewayService->createCoursePayment($registration, $course, $validated['payment_provider']);
                $payment = Payment::create([
                    'registration_id' => $registration->id,
                    'amount' => $course->price,
                    'provider' => $gatewayPayment['provider'],
                    'provider_payment_id' => $gatewayPayment['provider_payment_id'],
                    'checkout_url' => $gatewayPayment['checkout_url'],
                    'status' => 'Pending Payment',
                ]);

                return [
                    'registration' => $registration,
                    'payment' => $payment,
                ];
            });

            if ($course->type === 'paid') {
                return response()->json([
                    'message' => 'Registration created. Complete payment to confirm registration.',
                    'data' => [
                        'registration' => $registration['registration'],
                        'payment_status' => $registration['payment']->status,
                        'checkout_url' => $registration['payment']->checkout_url,
                    ],
                ], 201);
            }

            return response()->json([
                'message' => 'Registration created successfully',
                'data' => $registration['registration'],
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to process registration at this time.',
            ], 500);
        }
    }
}
