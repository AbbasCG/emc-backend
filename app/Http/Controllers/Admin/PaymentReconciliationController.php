<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentReconciliationResource;
use App\Models\Course;
use App\Models\LearningPathEnrollment;
use App\Models\PaymentReconciliation;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Services\AuditLogService;
use App\Services\LearnerEnrollmentGuard;
use App\Services\LearningPathEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The open items of confirmed payments whose enrollment was withheld (the payer teaches what they paid for).
 *
 * Settling one never moves money: a refund, if one is due, is made outside the platform and written in the note. What can be done
 * here is decide the ENROLLMENT, each with a required note and an audit entry:
 *   - activate the enrollment, only once the conflict is gone (the person was taken off the program), re-checked here;
 *   - close it without an enrollment (the registration is cancelled; the payment record and the invoice stay as they are).
 */
class PaymentReconciliationController extends Controller
{
    /**
     * GET /api/finance/payment-reconciliations?status=open|resolved&refund=pending&q=...
     *
     * `summary` always counts the whole table, so the screen's tabs show true numbers whatever filter is applied.
     */
    public function index(Request $request): JsonResponse
    {
        $items = PaymentReconciliation::query()
            ->with(['user:id,name,email', 'course:id,title,slug,program_type,instructor_id', 'registration:id,status', 'resolvedBy:id,name', 'payment.order.invoice'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->query('refund') === 'pending', fn ($q) => $q->where('refund_status', PaymentReconciliation::REFUND_PENDING))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.addcslashes((string) $request->query('q'), '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->whereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term))
                    ->orWhereHas('course', fn ($c) => $c->where('title', 'like', $term))
                    ->orWhereHas('payment', fn ($p) => $p->where('provider_payment_id', 'like', $term)));
            })
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'success' => true,
            'data'    => PaymentReconciliationResource::collection($items->getCollection())->resolve($request),
            'meta'    => ['total' => $items->total(), 'current_page' => $items->currentPage(), 'last_page' => $items->lastPage()],
            'summary' => [
                'open'           => PaymentReconciliation::where('status', PaymentReconciliation::STATUS_OPEN)->count(),
                'resolved'       => PaymentReconciliation::where('status', PaymentReconciliation::STATUS_RESOLVED)->count(),
                'refund_pending' => PaymentReconciliation::where('refund_status', PaymentReconciliation::REFUND_PENDING)->count(),
            ],
        ]);
    }

    /** POST /api/finance/payment-reconciliations/{reconciliation}/resolve */
    public function resolve(Request $request, int $reconciliation): JsonResponse
    {
        // Looked up here, not by route-model binding: binding runs BEFORE the role check, so an unauthorized role would learn which
        // ids exist (404 versus 403). The role middleware answers first; a missing case is a 404 only for people allowed to ask.
        $reconciliation = PaymentReconciliation::query()->findOrFail($reconciliation);

        $data = $request->validate([
            'resolution' => ['required', 'in:'.PaymentReconciliation::RESOLUTION_ACTIVATED.','.PaymentReconciliation::RESOLUTION_CLOSED],
            'note'       => ['required', 'string', 'min:5', 'max:2000'],
            // Only when closing without enrollment: money is owed back and must be returned OUTSIDE the platform. A to-do marker only.
            'refund_required' => ['sometimes', 'boolean'],
        ]);
        if (($data['refund_required'] ?? false) && $data['resolution'] !== PaymentReconciliation::RESOLUTION_CLOSED) {
            return response()->json(['success' => false, 'message' => 'يُطلب الاسترداد فقط عند إغلاق الحالة دون تفعيل التسجيل.', 'code' => 'refund_requires_closing'], 422);
        }

        // Activating grants learner access, so it takes the very locks an instructor assignment takes, as the FIRST statement of the
        // transaction (the scope is worked out before it: a plain read inside would fix the snapshot ahead of the locks). The teaching
        // check below is then read after the locks, and an assignment racing with this activation cannot both succeed.
        $guard = app(LearnerEnrollmentGuard::class);
        $scope = ['courses' => [], 'path' => null];
        if ($data['resolution'] === PaymentReconciliation::RESOLUTION_ACTIVATED) {
            $pathId = $reconciliation->learning_path_id
                ?: ($reconciliation->learning_path_enrollment_id ? LearningPathEnrollment::query()->whereKey($reconciliation->learning_path_enrollment_id)->value('learning_path_id') : null);
            $scope = $pathId
                ? ['courses' => $guard->pathCourseIds((int) $pathId), 'path' => (int) $pathId]
                : ['courses' => $reconciliation->course_id ? [(int) $reconciliation->course_id] : [], 'path' => null];
        }

        return DB::transaction(function () use ($request, $reconciliation, $data, $guard, $scope) {
            $guard->lockForEnrollment($scope['courses'], $scope['path']);
            $locked = PaymentReconciliation::query()->whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === PaymentReconciliation::STATUS_RESOLVED) {
                return response()->json(['success' => false, 'message' => 'تمت تسوية هذا البند من قبل.', 'code' => 'already_resolved'], 409);
            }

            if ($data['resolution'] === PaymentReconciliation::RESOLUTION_ACTIVATED) {
                if ($this->stillConflicts($locked)) {
                    return response()->json(['success' => false, 'code' => 'instructor_teaches_program',
                        'message' => 'لا يمكن تفعيل التسجيل: الدافع ما زال مدرّب هذا البرنامج. أزل إسناده أولاً.'], 422);
                }
                $this->activate($locked);
            } else {
                $this->close($locked);
            }

            $locked->update([
                'status'          => PaymentReconciliation::STATUS_RESOLVED,
                'resolution'      => $data['resolution'],
                'refund_status'   => ($data['refund_required'] ?? false) ? PaymentReconciliation::REFUND_PENDING : null,
                'resolution_note' => $data['note'],
                'resolved_by'     => $request->user()->id,
                'resolved_at'     => now(),
            ]);

            AuditLogService::log('payment_reconciliation_resolved', 'PaymentReconciliation', $locked->id, null, null, [
                'resolution' => $data['resolution'], 'payment_id' => $locked->payment_id, 'user_id' => $locked->user_id, 'refund_follow_up' => (bool) ($data['refund_required'] ?? false),
            ]);

            $locked->load(['user:id,name,email', 'course:id,title,slug,program_type,instructor_id', 'registration:id,status', 'resolvedBy:id,name', 'payment.order.invoice']);

            return response()->json(['success' => true, 'data' => (new PaymentReconciliationResource($locked))->resolve($request)]);
        });
    }

    private function stillConflicts(PaymentReconciliation $item): bool
    {
        $guard = app(LearnerEnrollmentGuard::class);
        $user = $item->user;

        if ($item->course_id) {
            $course = Course::find($item->course_id);

            return $course !== null && $user !== null && $guard->teachesCourse($user, $course);
        }

        $path = LearningPathEnrollment::find($item->learning_path_enrollment_id)?->learningPath;

        return $path !== null && $user !== null && $guard->teachesLearningPath($user, $path);
    }

    private function activate(PaymentReconciliation $item): void
    {
        if ($item->registration_id) {
            $registration = Registration::query()->lockForUpdate()->find($item->registration_id);
            $registration?->update(['status' => RegistrationStatus::REGISTERED->value]);
            if ($registration?->user_id && $registration->course_id) {
                StudentProgress::firstOrCreate(['user_id' => $registration->user_id, 'course_id' => $registration->course_id], ['status' => 'in_progress', 'progress_percentage' => 0]);
            }
        }

        if ($item->learning_path_enrollment_id) {
            $enrollment = LearningPathEnrollment::find($item->learning_path_enrollment_id);
            if ($enrollment) {
                app(LearningPathEnrollmentService::class)->activate($enrollment);
            }
        }
    }

    private function close(PaymentReconciliation $item): void
    {
        if ($item->registration_id) {
            Registration::query()->whereKey($item->registration_id)->update(['status' => RegistrationStatus::CANCELLED->value]);
        }

        if ($item->learning_path_enrollment_id) {
            LearningPathEnrollment::query()->whereKey($item->learning_path_enrollment_id)->update(['status' => 'cancelled']);
        }
    }
}
