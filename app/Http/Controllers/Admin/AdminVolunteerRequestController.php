<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\VolunteerRequestResource;
use App\Models\Department;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerRequest;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    // POST /api/admin/volunteer-requests/{id}/convert-to-member
    public function convertToMember(Request $request, VolunteerRequest $volunteerRequest): JsonResponse
    {
        // Role guard — admin/super_admin covered by route middleware; also allow hr_manager.
        $user = $request->user();
        if (! in_array($user->role, ['super_admin', 'tech_admin', 'admin', 'hr_manager'], true)) {
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

        try {
            // 3. Resolve department — best-effort match on name_ar / name; null is fine.
            $dept = $volunteerRequest->desired_department
                ? Department::where('name_ar', $volunteerRequest->desired_department)
                    ->orWhere('name_ar', 'like', '%' . $volunteerRequest->desired_department . '%')
                    ->orWhere('name', 'like', '%' . $volunteerRequest->desired_department . '%')
                    ->first()
                : null;

            // 4. sort_order: append after the last member in the department.
            $maxSort = $dept
                ? (TeamProfile::where('department_id', $dept->id)->max('sort_order') ?? 0)
                : (TeamProfile::whereNull('department_id')->max('sort_order') ?? 0);

            // 5. Ensure user account exists and assign department_member role
            $account = User::where('email', $volunteerRequest->email)->first();
            if (! $account) {
                $account = User::create([
                    'name'       => $volunteerRequest->full_name,
                    'email'      => $volunteerRequest->email,
                    'password'   => Hash::make(Str::random(16)),
                    'role'       => 'department_member',
                    'phone'      => $volunteerRequest->phone,
                    'country'    => $volunteerRequest->country,
                    'city'       => $volunteerRequest->city,
                    'gender'     => $volunteerRequest->gender,
                    'department' => $dept ? ($dept->name_ar ?? $dept->name) : null,
                    'is_active'  => true,
                ]);
            } elseif (in_array($account->role, ['student', 'volunteer'], true)) {
                $account->update([
                    'role'       => 'department_member',
                    'department' => $dept ? ($dept->name_ar ?? $dept->name) : $account->department,
                ]);
            }

            // 6. Create team_profiles row with full data mapping.
            $member = TeamProfile::create([
                'department_id' => $dept?->id,
                'user_id'       => $account->id,
                'name_ar'       => $volunteerRequest->full_name,
                'position_ar'   => 'عضو إدارة',
                'role_key'      => 'department_member',
                'phone'         => $volunteerRequest->phone,
                'email'         => $volunteerRequest->email,
                'country'       => $volunteerRequest->country,
                'city'          => $volunteerRequest->city,
                'gender'        => $volunteerRequest->gender,
                'skills'        => $volunteerRequest->skills,
                'cv_file_path'  => $volunteerRequest->cv_file_path,
                'sort_order'    => $maxSort + 1,
                'is_leader'     => false,
                'is_executive'  => false,
                'is_active'     => true,
                'is_new_member' => true,
                'member_type'   => 'volunteer',
                'source'        => 'volunteer_request',
                'source_id'     => $volunteerRequest->id,
                'joined_at'     => now(),
            ]);

            // 7. Register membership & place in unassigned queue for department manager
            if ($dept) {
                $account->departments()->syncWithoutDetaching([$dept->id => ['is_primary' => true]]);
                TeamMember::updateOrCreate(
                    ['team_profile_id' => $member->id],
                    [
                        'user_id'       => $account->id,
                        'department_id' => $dept->id,
                        'section_id'    => null, // unassigned, waiting for manager to seat them
                        'role_title'    => 'عضو إدارة',
                        'status'        => 'active',
                        'joined_at'     => now(),
                    ]
                );
            }

            // 8. Stamp the volunteer request with conversion metadata.
            $volunteerRequest->update([
                'status'                 => 'converted_to_member',
                'converted_to_member_at' => now(),
                'converted_member_id'    => $member->id,
                'converted_by'           => $request->user()->id,
            ]);

            // 9. Notify admins and department leader.
            NotificationService::volunteerConvertedToMember(
                $volunteerRequest->id,
                $member->id,
                $volunteerRequest->full_name,
                $volunteerRequest->desired_department ?? '',
                $dept?->leader_id,
            );

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('convertToMember failed', [
                'volunteer_request_id' => $volunteerRequest->id,
                'error'                => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فشل تحويل المتطوع: ' . $e->getMessage(),
            ], 500);
        }

        $updatedVr = $volunteerRequest->fresh()->load('reviewer');

        return response()->json([
            'success'           => true,
            'message'           => 'تم تحويل المتطوع إلى عضو بنجاح.',
            // Frontend convertVolunteerToMember() reads raw.volunteer_request first.
            'volunteer_request' => new VolunteerRequestResource($updatedVr),
            // Backward-compatible data key with the member snapshot.
            'data'              => [
                'id'              => $member->id,
                'name_ar'         => $member->name_ar,
                'position_ar'     => $member->position_ar,
                'role_key'        => $member->role_key,
                'department_id'   => $member->department_id,
                'department_name' => $dept?->name_ar,
                'member_type'     => $member->member_type,
                'source'          => $member->source,
                'source_id'       => $member->source_id,
                'is_new_member'   => $member->is_new_member,
                'joined_at'       => $member->joined_at,
                'is_active'       => $member->is_active,
                'has_user_account'=> false,
            ],
        ], 201);
    }
}
