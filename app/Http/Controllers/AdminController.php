<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\RegistrationResource;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Models\Track;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Support\PaymentStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
                'registered_count'          => Registration::where('status', RegistrationStatus::REGISTERED->value)->count(),
                'pending_payment_count'     => Registration::where('status', RegistrationStatus::PENDING_PAYMENT->value)->count(),
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

        // has_account filter: 1 = linked account (user_id IS NOT NULL), 0 = guest (user_id IS NULL)
        if ($request->filled('has_account')) {
            $val = (string) $request->query('has_account');
            if ($val === '1' || $val === 'true' || $val === 'linked') {
                $query->whereNotNull('user_id');
            } elseif ($val === '0' || $val === 'false' || $val === 'guest') {
                $query->whereNull('user_id');
            }
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
        $allowedStatuses = array_column(RegistrationStatus::cases(), 'value');

        $validated = $request->validate([
            'status' => 'required|in:' . implode(',', $allowedStatuses),
        ]);

        $oldStatus = $registration->status;
        $registration->update(['status' => $validated['status']]);

        if ($validated['status'] === RegistrationStatus::PAYMENT_CONFIRMED->value) {
            Payment::where('registration_id', $registration->id)->update([
                'status'       => PaymentStatuses::CONFIRMED,
                'confirmed_at' => now(),
            ]);
        }

        AuditLogService::log('status_changed', 'Registration', $registration->id,
            $registration->full_name,
            ['status' => $oldStatus],
            ['status' => $validated['status']],
            null, $request
        );

        // Ensure student_progress exists when enrollment is confirmed
        if (in_array($validated['status'], [RegistrationStatus::REGISTERED->value, RegistrationStatus::PAYMENT_CONFIRMED->value], true)
            && $registration->user_id
            && $registration->course_id
        ) {
            $totalSessions = $registration->course_id
                ? \App\Models\Course::find($registration->course_id)?->sessions()->count() ?? 0
                : 0;
            StudentProgress::updateOrCreate(
                ['user_id' => $registration->user_id, 'course_id' => $registration->course_id],
                [
                    'progress_percentage'              => 0,
                    'status'                           => 'in_progress',
                    'total_sessions'                   => $totalSessions,
                    'completed_sessions'               => 0,
                    'attendance_percentage'            => 0,
                    'assignment_completion_percentage' => 0,
                ]
            );
        }

        if (in_array($validated['status'], [RegistrationStatus::REGISTERED->value, RegistrationStatus::PAYMENT_CONFIRMED->value], true)
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

    /**
     * POST /api/admin/registrations/{registration}/create-account
     *
     * Creates a user account from a guest registration (user_id = null).
     * If a user with the same email already exists, links them without creating a duplicate.
     */
    public function createAccountForRegistration(Request $request, Registration $registration): JsonResponse
    {
        if ($registration->user_id) {
            $registration->load('user');
            return response()->json([
                'success' => false,
                'message' => 'هذا التسجيل مرتبط بحساب مستخدم بالفعل.',
                'data'    => ['user_id' => $registration->user_id],
            ], 409);
        }

        if (! $registration->email) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد بريد إلكتروني في التسجيل — لا يمكن إنشاء حساب.',
            ], 422);
        }

        // Reuse an existing user with the same email instead of creating a duplicate.
        $existing = User::where('email', $registration->email)->first();
        if ($existing) {
            $registration->update(['user_id' => $existing->id]);
            // Sync progress for ALL active registrations of this user
            $this->syncProgressForUser($existing->id);
            $registration->load(['course.track', 'course.instructor', 'user', 'payments']);

            return response()->json([
                'success' => true,
                'message' => 'تم ربط الحساب الموجود بهذا التسجيل.',
                'data'    => [
                    'user_id'  => $existing->id,
                    'created'  => false,
                    'linked'   => true,
                    'registration' => new RegistrationResource($registration),
                ],
            ]);
        }

        // Create a new student account.
        $tempPassword = Str::random(12);
        $user = User::create([
            'name'                 => $registration->full_name ?? 'مستخدم جديد',
            'email'                => $registration->email,
            'password'             => Hash::make($tempPassword),
            'role'                 => 'student',
            'phone'                => $registration->phone,
            'is_active'            => true,
            'must_change_password' => true,
        ]);

        $registration->update(['user_id' => $user->id]);
        // Create student_progress for all active registrations of this new user
        $this->syncProgressForUser($user->id);
        $registration->load(['course.track', 'course.instructor', 'user', 'payments']);

        AuditLogService::log(
            'account_created_from_registration',
            'Registration',
            $registration->id,
            $registration->full_name,
            [],
            ['user_id' => $user->id, 'email' => $user->email],
            null,
            $request
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء الحساب وربطه بالتسجيل.',
            'data'    => [
                'user_id'      => $user->id,
                'created'      => true,
                'linked'       => false,
                'registration' => new RegistrationResource($registration),
            ],
        ], 201);
    }

    /**
     * GET /api/admin/students/{user}/courses
     *
     * Returns the user's active course registrations with their StudentProgress records.
     */
    public function studentCourses(User $user): JsonResponse
    {
        $registrations = Registration::with(['course'])
            ->where('user_id', $user->id)
            ->whereNotNull('course_id')
            ->whereNotIn('status', [RegistrationStatus::CANCELLED->value])
            ->latest()
            ->get();

        $progressRows = \DB::table('student_progress')
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('course_id');

        $data = $registrations->map(function (Registration $reg) use ($progressRows) {
            $prog = $progressRows->get($reg->course_id);
            return [
                'course_id'       => $reg->course_id,
                'course_title'    => $reg->course?->title ?? "دورة #{$reg->course_id}",
                'status'          => $reg->status,
                'registered_at'   => $reg->created_at?->toDateTimeString(),
                'progress_status' => $prog?->status,
                'progress_pct'    => (int) ($prog?->progress_percentage ?? 0),
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
            'total'   => $data->count(),
        ]);
    }

    /**
     * POST /api/admin/registrations/repair-links
     *
     * Links guest registrations to existing user accounts by matching email,
     * then backfills missing student_progress records for active registrations.
     * Returns counts of what was fixed.
     */
    public function repairRegistrationLinks(Request $request): JsonResponse
    {
        $linked          = 0;
        $progressCreated = 0;
        $skipped         = 0;

        // ── Step 1: Link guest registrations that share an email with a known user ──
        Registration::whereNull('user_id')
            ->whereNotNull('email')
            ->whereNotIn('status', [RegistrationStatus::CANCELLED->value])
            ->chunk(200, function ($registrations) use (&$linked) {
                foreach ($registrations as $reg) {
                    $user = User::where('email', mb_strtolower(trim((string) $reg->email)))->first();
                    if (! $user) {
                        continue;
                    }
                    $reg->update(['user_id' => $user->id]);
                    $linked++;
                }
            });

        // ── Step 2: Create missing student_progress for active registrations ─────
        $activeStatuses = [
            RegistrationStatus::REGISTERED->value,
            RegistrationStatus::PAYMENT_CONFIRMED->value,
            RegistrationStatus::ATTENDED->value,
        ];

        Registration::whereNotNull('user_id')
            ->whereNotNull('course_id')
            ->whereIn('status', $activeStatuses)
            ->chunk(200, function ($registrations) use (&$progressCreated, &$skipped) {
                foreach ($registrations as $reg) {
                    $exists = StudentProgress::where('user_id',   $reg->user_id)
                                             ->where('course_id', $reg->course_id)
                                             ->exists();
                    if ($exists) {
                        $skipped++;
                        continue;
                    }
                    StudentProgress::create([
                        'user_id'                          => $reg->user_id,
                        'course_id'                        => $reg->course_id,
                        'progress_percentage'              => 0,
                        'status'                           => 'in_progress',
                        'total_sessions'                   => 0,
                        'completed_sessions'               => 0,
                        'attendance_percentage'            => 0,
                        'assignment_completion_percentage' => 0,
                    ]);
                    $progressCreated++;
                }
            });

        return response()->json([
            'success'                   => true,
            'message'                   => "تم ربط {$linked} تسجيل وإنشاء {$progressCreated} سجل تقدم.",
            'linked_registrations'      => $linked,
            'created_progress_records'  => $progressCreated,
            'skipped_duplicates'        => $skipped,
        ]);
    }

    /**
     * Create or update student_progress for all active registrations belonging to a user.
     * Called whenever a user account is linked to one or more registrations.
     */
    private function syncProgressForUser(int $userId): void
    {
        $activeStatuses = [
            RegistrationStatus::REGISTERED->value,
            RegistrationStatus::PAYMENT_CONFIRMED->value,
            RegistrationStatus::ATTENDED->value,
        ];

        Registration::where('user_id', $userId)
            ->whereNotNull('course_id')
            ->whereIn('status', $activeStatuses)
            ->each(function (Registration $reg) use ($userId) {
                StudentProgress::updateOrCreate(
                    ['user_id' => $userId, 'course_id' => $reg->course_id],
                    [
                        'progress_percentage'              => 0,
                        'status'                           => 'in_progress',
                        'total_sessions'                   => 0,
                        'completed_sessions'               => 0,
                        'attendance_percentage'            => 0,
                        'assignment_completion_percentage' => 0,
                    ]
                );
            });
    }
}
