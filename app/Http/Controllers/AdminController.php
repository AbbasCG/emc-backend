<?php

namespace App\Http\Controllers;

use App\Http\Resources\PaymentResource;
use App\Http\Resources\RegistrationResource;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\Track;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\PaymentStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class AdminController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $recentRegistrations = Registration::with(['course.track', 'course.instructor', 'payments'])
            ->latest()
            ->take(5)
            ->get();

        $recentPayments = Payment::with(['registration.course'])
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Dashboard data retrieved successfully',
            'data'    => [
                'total_courses'             => Course::count(),
                'total_tracks'              => Track::count(),
                'total_instructors'         => Instructor::count(),
                'total_users'               => User::count(),
                'total_registrations'       => Registration::count(),
                'registered_count'          => Registration::where('status', 'Registered')->count(),
                'pending_payment_count'     => Registration::where('status', 'Pending Payment')->count(),
                'paid_count'                => Payment::where('status', PaymentStatuses::CONFIRMED)->count(),
                'failed_payment_count'      => Payment::where('status', PaymentStatuses::FAILED)->count(),
                'total_revenue_confirmed'   => (float) Payment::where('status', PaymentStatuses::CONFIRMED)->sum('amount'),
                'recent_registrations'      => RegistrationResource::collection($recentRegistrations),
                'recent_payments'           => PaymentResource::collection($recentPayments),
            ],
        ]);
    }

    public function registrations(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        // Load user even when user_id is NULL — whereNotNull not used so nulls appear too
        $query = Registration::with(['course.track', 'course.instructor.user', 'payments', 'user']);

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->query('course_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('payment_status')) {
            $query->whereHas('payments', fn ($q) => $q->where('status', $request->query('payment_status')));
        }

        // Accept both ?q= and ?search= for backwards compat
        $search = $request->query('q') ?? $request->query('search');
        if (filled($search)) {
            $term = (string) $search;
            $query->where(function ($sub) use ($term) {
                $sub->where('full_name', 'like', "%{$term}%")
                    ->orWhere('email',     'like', "%{$term}%")
                    ->orWhere('phone',     'like', "%{$term}%")
                    ->orWhereHas('course', fn ($c) => $c->where('title', 'like', "%{$term}%"));
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $registrations = $query->latest()->paginate($perPage);

        return RegistrationResource::collection($registrations)
            ->additional([
                'success' => true,
                'message' => 'Registrations retrieved successfully',
            ]);
    }

    public function showRegistration(Registration $registration): JsonResponse
    {
        $registration->load(['course.track', 'course.instructor', 'course.features', 'payments']);

        return (new RegistrationResource($registration))
            ->additional([
                'success' => true,
                'message' => 'Registration retrieved successfully',
            ])
            ->response();
    }

    public function updateRegistrationStatus(Request $request, Registration $registration): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:Registered,Pending Payment,Payment Confirmed,Cancelled,Attended,No Show',
        ]);

        $registration->update(['status' => $validated['status']]);

        if ($validated['status'] === 'Payment Confirmed') {
            Payment::where('registration_id', $registration->id)->update([
                'status'       => PaymentStatuses::CONFIRMED,
                'confirmed_at' => now(),
            ]);
        }

        if (in_array($validated['status'], ['Registered', 'Payment Confirmed'], true)
            && $registration->user_id
        ) {
            try {
                NotificationService::registrationConfirmed(
                    $registration->user_id,
                    $registration->course_id,
                    $registration->course?->title ?? 'Unknown Course',
                    $registration->id,
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        $registration->load(['course.track', 'course.instructor', 'payments']);

        return (new RegistrationResource($registration))
            ->additional([
                'success' => true,
                'message' => 'Registration status updated successfully',
            ])
            ->response();
    }
}
