<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\VolunteerRequestResource;
use App\Exceptions\VolunteerConversionException;
use App\Models\Department;
use App\Models\VolunteerRequest;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Services\VolunteerConversionService;
use App\Support\ThrottleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminVolunteerRequestController extends Controller
{
    // GET /api/admin/volunteer-requests
    // ?status= &desired_department= &search= &date_from= &date_to= &per_page= &page=
    public function index(Request $request): JsonResponse
    {
        // ── Base query: filters shared by both the list and the statistics ──
        $base = VolunteerRequest::query()
            ->when($request->filled('desired_department'), fn ($q) => $q->where('desired_department', $request->desired_department))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->search;
                $q->where(function ($sub) use ($term) {
                    $sub->where('full_name', 'like', "%{$term}%")
                        ->orWhere('email',     'like', "%{$term}%")
                        ->orWhere('phone',     'like', "%{$term}%");
                });
            })
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'),   fn ($q) => $q->whereDate('created_at', '<=', $request->date_to));

        // ── Statistics (filter-aware but status-agnostic for correct card counts) ──
        $statusCounts = (clone $base)
            ->select('status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $statistics = [
            'total'               => $statusCounts->sum(),
            'pending'             => (int) ($statusCounts['pending']             ?? 0),
            'reviewing'           => (int) ($statusCounts['reviewing']           ?? 0),
            'reviewed'            => (int) ($statusCounts['reviewed']            ?? 0),
            'accepted'            => (int) ($statusCounts['accepted']            ?? 0),
            'rejected'            => (int) ($statusCounts['rejected']            ?? 0),
            'contacted'           => (int) ($statusCounts['contacted']           ?? 0),
            'converted_to_member' => (int) ($statusCounts['converted_to_member'] ?? 0),
        ];

        // ── Paginated list (adds status filter on top of base) ──
        $perPage  = min((int) $request->query('per_page', 20), 100);
        $requests = (clone $base)
            ->with(['reviewer', 'opportunity:id,title'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        $paginated = VolunteerRequestResource::collection($requests)->response()->getData(true);

        return response()->json([
            'success'    => true,
            'data'       => $paginated['data']  ?? [],
            'meta'       => $paginated['meta']  ?? [],
            'links'      => $paginated['links'] ?? [],
            'statistics' => $statistics,
        ]);
    }

    // GET /api/admin/volunteer-requests/{id}
    public function show(VolunteerRequest $volunteerRequest): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new VolunteerRequestResource($volunteerRequest->load('reviewer')),
        ]);
    }

    // GET /api/admin/volunteers/accepted
    public function acceptedIndex(Request $request): JsonResponse
    {
        $query = VolunteerRequest::with(['reviewer', 'acceptedBy'])
            ->where('status', 'accepted')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->search;
                $q->where(function ($sub) use ($term) {
                    $sub->where('full_name',         'like', "%{$term}%")
                        ->orWhere('email',            'like', "%{$term}%")
                        ->orWhere('phone',            'like', "%{$term}%");
                });
            })
            ->when($request->filled('desired_department'), fn ($q) => $q->where('desired_department', $request->desired_department))
            ->when($request->filled('city'),   fn ($q) => $q->where('city',   $request->city))
            ->when($request->filled('gender'), fn ($q) => $q->where('gender', $request->gender))
            ->when($request->filled('availability'), fn ($q) => $q->where('availability', 'like', '%' . $request->availability . '%'))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('accepted_at', '>=', $request->date_from))
            ->when($request->filled('date_to'),   fn ($q) => $q->whereDate('accepted_at', '<=', $request->date_to))
            ->when($request->filled('has_cv'), function ($q) use ($request) {
                $request->has_cv === 'yes'
                    ? $q->whereNotNull('cv_file_path')
                    : $q->whereNull('cv_file_path');
            });

        $sortField = match ($request->query('sort', 'accepted_asc')) {
            'accepted_desc' => ['accepted_at', 'desc'],
            'name_asc'      => ['full_name',   'asc'],
            'department'    => ['desired_department', 'asc'],
            'city'          => ['city',         'asc'],
            default         => ['accepted_at',  'asc'],  // oldest accepted first
        };

        $query->orderBy($sortField[0], $sortField[1]);

        $perPage = min((int) $request->query('per_page', 50), 200);
        $items   = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => VolunteerRequestResource::collection($items)->response()->getData(true),
            'meta'    => [
                'total_accepted'      => VolunteerRequest::where('status', 'accepted')->count(),
                'total_converted'     => VolunteerRequest::where('status', 'converted_to_member')->count(),
                'accepted_this_month' => VolunteerRequest::where('status', 'accepted')
                    ->whereMonth('accepted_at', now()->month)
                    ->whereYear('accepted_at',  now()->year)
                    ->count(),
                'top_department' => VolunteerRequest::where('status', 'accepted')
                    ->select('desired_department', \Illuminate\Support\Facades\DB::raw('count(*) as cnt'))
                    ->groupBy('desired_department')
                    ->orderByDesc('cnt')
                    ->value('desired_department'),
            ],
        ]);
    }

    // PATCH /api/admin/volunteer-requests/{id}/status
    public function updateStatus(Request $request, VolunteerRequest $volunteerRequest): JsonResponse
    {
        // A converted request is final: its member, account and hub profile exist. Moving it back to accepted / rejected / pending would
        // leave a status that contradicts converted_member_id, so no status change is accepted, whatever the target.
        if ($volunteerRequest->isConverted() || $volunteerRequest->status === 'converted_to_member') {
            return response()->json([
                'success'           => false,
                'already_converted' => true,
                'message'           => 'تم تحويل هذا الطلب إلى عضو، ولا يمكن تغيير حالته.',
                'volunteer_request' => new VolunteerRequestResource($volunteerRequest->load('reviewer')),
            ], 409);
        }

        $data = $request->validate([
            'status'      => 'required|string|in:' . implode(',', VolunteerRequest::allowedStatuses()),
            'admin_notes' => 'nullable|string',
        ]);

        $newStatusNorm = VolunteerRequest::normalizeStatus($data['status']);

        $updates = [
            'status'      => $newStatusNorm,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ];

        // Stamp accepted_at/accepted_by the first time status becomes 'accepted'
        if ($newStatusNorm === 'accepted' && $volunteerRequest->accepted_at === null) {
            $updates['accepted_at'] = now();
            $updates['accepted_by'] = $request->user()->id;
        }

        if (array_key_exists('admin_notes', $data)) {
            $updates['admin_notes'] = $data['admin_notes'];
        }

        $oldStatus = $volunteerRequest->status;
        $volunteerRequest->update($updates);

        $fresh = $volunteerRequest->fresh()->load('reviewer');

        $newStatus = $fresh->status;
        $actor     = $request->user();
        if (in_array($newStatus, ['accepted', 'approved'], true)) {
            AuditLogService::log(
                action:      'APPROVE',
                entityType:  'VolunteerRequest',
                entityId:    $fresh->id,
                entityName:  $fresh->full_name,
                oldValues:   ['status' => $oldStatus],
                newValues:   ['status' => $newStatus],
                request:     $request,
                description: $actor->name . ' approved volunteer request from "' . $fresh->full_name . '"',
            );
        } elseif (in_array($newStatus, ['rejected', 'declined'], true)) {
            AuditLogService::log(
                action:      'REJECT',
                entityType:  'VolunteerRequest',
                entityId:    $fresh->id,
                entityName:  $fresh->full_name,
                oldValues:   ['status' => $oldStatus],
                newValues:   ['status' => $newStatus],
                request:     $request,
                description: $actor->name . ' rejected volunteer request from "' . $fresh->full_name . '"',
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Status updated.',
            'data'    => new VolunteerRequestResource($fresh),
            // Tells the frontend it can offer the "Convert to member" action
            'can_convert_to_member' => $fresh->status === 'accepted' && $fresh->converted_to_member_at === null,
        ]);
    }

    // downloadCv() removed — privacy policy: staff may preview an applicant's
    // CV in-app but never download the raw file.

    // GET /api/admin/volunteer-requests/{id}/cv/view  (signed URL — inline view)
    public function viewCv(Request $request, VolunteerRequest $volunteerRequest): StreamedResponse|JsonResponse
    {
        $path = $volunteerRequest->cvStoragePath();

        [$disk, $resolvedPath] = $this->resolveCvDisk($path);

        if (! $disk) {
            return response()->json([
                'success' => false,
                'message' => 'CV file not found.',
            ], 404);
        }

        return Storage::disk($disk)->response($resolvedPath);
    }

    /**
     * Backward-compatible disk resolution: new CVs are stored on the private
     * disk, but CVs uploaded before this fix may still be on `public`.
     */
    private function resolveCvDisk(?string $path): array
    {
        if (! $path) {
            return [null, $path];
        }

        if (Storage::disk('private')->exists($path)) {
            return ['private', $path];
        }

        if (Storage::disk('public')->exists($path)) {
            return ['public', $path];
        }

        return [null, $path];
    }

    private const CONVERT_ROLES = ['super_admin', 'tech_admin', 'admin', 'hr_manager'];

    // POST /api/admin/volunteer-requests/{id}/convert-to-member    body: { department_id }
    //
    // HR confirms (or changes) the department the volunteer applied for; the whole conversion (account, department membership, team
    // profile, volunteer-hub profile, request stamp) is one transaction in VolunteerConversionService. The e-mail goes out after commit.
    public function convertToMember(Request $request, VolunteerRequest $volunteerRequest, VolunteerConversionService $conversion): JsonResponse
    {
        // Role guard — admin/super_admin covered by route middleware; also allow hr_manager.
        $actor = $request->user();
        if (! in_array($actor->role, self::CONVERT_ROLES, true)) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بهذا الإجراء.'], 403);
        }

        // 1. Prevent duplicate conversion before validating the current
        // status; successful conversion intentionally changes it.
        if ($volunteerRequest->isConverted()) {
            return response()->json([
                'success'           => false,
                'already_converted' => true,
                'message'           => 'تم تحويل هذا الطلب مسبقاً إلى عضو.',
                'volunteer_request' => new VolunteerRequestResource($volunteerRequest->load('reviewer')),
            ], 409);
        }

        // 2. Must be accepted before converting.
        if ($volunteerRequest->status !== 'accepted') {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تحويل الطلب إلا إذا كان مقبولاً. الحالة الحالية: ' . $volunteerRequest->status,
            ], 422);
        }

        // 3. The department HR picked in the popup is required.
        $validator = Validator::make($request->all(), [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
        ], [
            'department_id.required' => 'اختر الإدارة التي سينضم إليها المتطوع.',
            'department_id.integer'  => 'الإدارة المختارة غير صالحة.',
            'department_id.exists'   => 'الإدارة المختارة غير موجودة.',
        ]);
        $department = $validator->fails() ? null : Department::find((int) $request->input('department_id'));

        if ($department !== null && $department->status === 'archived') {
            $validator->errors()->add('department_id', 'الإدارة المختارة مؤرشفة ولا يمكن الانضمام إليها.');
            $department = null;
        }
        if ($department === null) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first('department_id') ?: 'الإدارة المختارة غير صالحة.',
                'errors'  => ['department_id' => $validator->errors()->get('department_id')],
            ], 422);
        }

        try {
            $result = $conversion->convert($volunteerRequest, $department, $actor);
        } catch (VolunteerConversionException $e) {
            $payload = ['success' => false, 'message' => $e->getMessage()];
            if ($e->reason === VolunteerConversionException::ALREADY_CONVERTED) {
                $payload['already_converted'] = true;
                $payload['volunteer_request'] = new VolunteerRequestResource($volunteerRequest->fresh()->load('reviewer'));
            }

            return response()->json($payload, $e->status);
        } catch (\Throwable $e) {
            Log::error('convertToMember failed', [
                'volunteer_request_id' => $volunteerRequest->id,
                'error'                => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فشل تحويل المتطوع. لم يتم حفظ أي تغيير، حاول مرة أخرى.',
            ], 500);
        }

        /** @var \App\Models\TeamProfile $member */
        $member   = $result['member'];
        $account  = $result['account'];
        // The department the member really landed in: an existing placement wins over the one HR picked.
        $placed   = $result['department'];
        $deptName = (string) ($placed->name_ar ?? $placed->name);

        // ── After commit: nothing below may undo or fail the conversion ──
        $emailSent = $conversion->sendAcceptedMail($account, $deptName, $result['temporary_password']);

        try {
            NotificationService::volunteerConvertedToMember(
                $volunteerRequest->id,
                $member->id,
                $volunteerRequest->full_name,
                $deptName,
                $placed->leader_id,
            );
        } catch (\Throwable $e) {
            Log::warning('convertToMember notification failed', ['volunteer_request_id' => $volunteerRequest->id, 'error' => $e->getMessage()]);
        }

        try {
            AuditLogService::log(
                action:      'CONVERT_TO_MEMBER',
                entityType:  'VolunteerRequest',
                entityId:    $volunteerRequest->id,
                entityName:  $volunteerRequest->full_name,
                oldValues:   ['status' => 'accepted'],
                newValues:   ['status' => 'converted_to_member', 'department_id' => $placed->id, 'user_id' => $account->id],
                extra:       ['account_created' => $result['account_created'], 'credentials_reissued' => $result['credentials_reissued'], 'requested_department_id' => $department->id, 'email_sent' => $emailSent],
                request:     $request,
                description: $actor->name . ' converted volunteer "' . $volunteerRequest->full_name . '" to a member of "' . $deptName . '"',
            );
        } catch (\Throwable $e) {
            Log::warning('convertToMember audit log failed', ['volunteer_request_id' => $volunteerRequest->id, 'error' => $e->getMessage()]);
        }

        $updatedVr = $volunteerRequest->fresh()->load('reviewer');

        return response()->json([
            'success'           => true,
            'message'           => $emailSent
                ? 'تم تحويل المتطوع إلى عضو وإنشاء حسابه وإرسال بيانات الدخول إلى بريده.'
                : 'تم تحويل المتطوع إلى عضو. تعذّر إرسال البريد، يمكنك إعادة إرسال بيانات الدخول لاحقاً.',
            // Frontend convertVolunteerToMember() reads raw.volunteer_request first.
            'volunteer_request' => new VolunteerRequestResource($updatedVr),
            // The temporary password is never part of any response.
            'data'              => [
                'id'               => $member->id,
                'name_ar'          => $member->name_ar,
                'position_ar'      => $member->position_ar,
                'role_key'         => $member->role_key,
                'department_id'    => $placed->id,
                'department_name'  => $deptName,
                // true when the account already belonged to another department and keeps it instead of the one chosen
                'department_kept_existing' => $placed->id !== $department->id,
                'member_type'      => $member->member_type,
                'source'           => $member->source,
                'source_id'        => $member->source_id,
                'is_new_member'    => $member->is_new_member,
                'joined_at'        => $member->joined_at,
                'is_active'        => $member->is_active,
                'has_user_account' => true,
                'user_id'          => $account->id,
                'account_created'  => $result['account_created'],
                'account_existing' => $result['account_existing'],
                'credentials_reissued' => $result['credentials_reissued'],
                'email_sent'       => $emailSent,
            ],
        ], 201);
    }

    // POST /api/admin/volunteer-requests/{id}/resend-credentials
    //
    // For a converted volunteer whose account still has the temporary password: issues a NEW one and mails it again.
    public function resendCredentials(Request $request, VolunteerRequest $volunteerRequest, VolunteerConversionService $conversion): JsonResponse
    {
        $actor = $request->user();
        if (! in_array($actor->role, self::CONVERT_ROLES, true)) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بهذا الإجراء.'], 403);
        }

        // Per-target allowance: one delivered resend per 10 minutes, ten per day, whoever asks. It is only COUNTED after a mail was
        // really delivered (below), so a failed send leaves it untouched; it is checked here, before anything is reissued.
        $cooldownKey = 'vr-resend-target:' . $volunteerRequest->id;
        $dayKey      = 'vr-resend-day:' . $volunteerRequest->id;
        foreach ([[$cooldownKey, 1], [$dayKey, 10]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return ThrottleResponse::make(RateLimiter::availableIn($key));
            }
        }

        try {
            $issued = $conversion->reissueCredentials($volunteerRequest);
        } catch (VolunteerConversionException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->status);
        }

        $emailSent = $conversion->sendAcceptedMail($issued['account'], $issued['department_name'], $issued['temporary_password']);

        if ($emailSent) {
            RateLimiter::hit($cooldownKey, 600);
            RateLimiter::hit($dayKey, 86400);
        }

        try {
            AuditLogService::log(
                action:      'RESEND_CREDENTIALS',
                entityType:  'VolunteerRequest',
                entityId:    $volunteerRequest->id,
                entityName:  $volunteerRequest->full_name,
                newValues:   ['user_id' => $issued['account']->id],
                extra:       ['email_sent' => $emailSent],
                request:     $request,
                description: $actor->name . ' re-issued the temporary password of volunteer "' . $volunteerRequest->full_name . '"',
            );
        } catch (\Throwable $e) {
            Log::warning('resendCredentials audit log failed', ['volunteer_request_id' => $volunteerRequest->id, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'success'    => true,
            'email_sent' => $emailSent,
            'message'    => $emailSent
                ? 'تم إنشاء كلمة مرور مؤقتة جديدة وإرسالها إلى بريد المتطوع.'
                : 'تم إنشاء كلمة مرور مؤقتة جديدة لكن تعذّر إرسال البريد. حاول مرة أخرى.',
        ]);
    }
}
