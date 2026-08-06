<?php

namespace App\Http\Controllers;

use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminPaymentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $query = Payment::query()
            ->with(['registration' => fn ($q) => $q->with(['course.track', 'course.instructor'])])
            ->latest();

        if ($request->filled('provider')) {
            $query->where('provider', $request->query('provider'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('registration_id')) {
            $query->where('registration_id', $request->query('registration_id'));
        }

        if ($request->filled('course_id')) {
            $query->whereHas('registration', fn ($q) => $q->where('course_id', $request->query('course_id')));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        return PaymentResource::collection($query->paginate($perPage))
            ->additional([
                'success' => true,
                'message' => 'Payments retrieved successfully',
            ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load(['registration.course.track', 'registration.course.instructor', 'registration.course.features']);

        return (new PaymentResource($payment))
            ->additional([
                'success' => true,
                'message' => 'Payment retrieved successfully',
            ])
            ->response();
    }
}
