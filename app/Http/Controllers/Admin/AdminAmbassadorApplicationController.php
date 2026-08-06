<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AmbassadorApplicationResource;
use App\Mail\AmbassadorStatusChangedMail;
use App\Models\AmbassadorApplication;
use App\Models\AmbassadorApplicationNote;
use App\Models\AmbassadorApplicationStatusHistory;
use App\Services\AuditLogService;
use App\Services\EmailService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAmbassadorApplicationController extends Controller
{
    /**
     * GET /api/admin/ambassador-applications
     * Query params: status, country, city, university|university_name,
     *               specialization|major, gender, search, date_from, date_to, per_page, page
     */
    public function index(Request $request): JsonResponse
    {
        // Filter-aware base excludes status so KPI cards stay status-agnostic.
        $base = $this->filteredQuery($request, includeStatus: false);

        $statusCounts = (clone $base)
            ->select('status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $statistics = [
            'total'               => $statusCounts->sum(),
            'new'                 => (int) ($statusCounts['new']                  ?? 0),
            'under_review'        => (int) ($statusCounts['under_review']         ?? 0),
            'interview_scheduled' => (int) ($statusCounts['interview_scheduled']  ?? 0),
            'approved'            => (int) ($statusCounts['approved']             ?? 0),
            'rejected'            => (int) ($statusCounts['rejected']             ?? 0),
            'waitlisted'          => (int) ($statusCounts['waitlisted']           ?? 0),
            'cancelled'           => (int) ($statusCounts['cancelled']            ?? 0),
        ];

        $byCountry = (clone $base)
            ->select('country', DB::raw('COUNT(*) as cnt'))
            ->groupBy('country')
            ->orderByDesc('cnt')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['country' => $r->country, 'count' => (int) $r->cnt]);

        $driver = DB::connection()->getDriverName();
        $monthExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        $monthlyTrend = (clone $base)
            ->select(DB::raw("{$monthExpr} as month"), DB::raw('COUNT(*) as cnt'))
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->get()
            ->map(fn ($r) => ['month' => $r->month, 'count' => (int) $r->cnt]);

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $paginated = $this->filteredQuery($request, includeStatus: true)
            ->with(['reviewer', 'statusUpdatedBy'])
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        $paginatedData = AmbassadorApplicationResource::collection($paginated)->response()->getData(true);

        return response()->json([
            'success'       => true,
            'data'          => $paginatedData['data']  ?? [],
            'meta'          => $paginatedData['meta']  ?? [],
            'links'         => $paginatedData['links'] ?? [],
            'statistics'    => $statistics,
            'by_country'    => $byCountry,
            'monthly_trend' => $monthlyTrend,
        ]);
    }

    /**
     * GET /api/admin/ambassador-applications/filter-options
     * Optional query: country, city — narrow dependent city/university lists.
     */
    public function filterOptions(Request $request): JsonResponse
    {
        $base = AmbassadorApplication::query()->where('is_draft', false);

        $country = trim((string) $request->query('country', ''));
        $city = trim((string) $request->query('city', ''));

        $countries = (clone $base)
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->distinct()
            ->orderBy('country')
            ->pluck('country')
            ->values();

        $citiesQuery = (clone $base)
            ->whereNotNull('city')
            ->where('city', '!=', '');
        if ($country !== '') {
            $citiesQuery->where('country', $country);
        }
        $cities = $citiesQuery->distinct()->orderBy('city')->pluck('city')->values();

        $universitiesQuery = (clone $base)
            ->whereNotNull('university_name')
            ->where('university_name', '!=', '');
        if ($country !== '') {
            $universitiesQuery->where('country', $country);
        }
        if ($city !== '') {
            $universitiesQuery->where('city', $city);
        }
        $universities = $universitiesQuery->distinct()->orderBy('university_name')->pluck('university_name')->values();

        $specializations = (clone $base)
            ->whereNotNull('major')
            ->where('major', '!=', '')
            ->distinct()
            ->orderBy('major')
            ->pluck('major')
            ->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'countries'       => $countries,
                'cities'          => $cities,
                'universities'    => $universities,
                'specializations' => $specializations,
            ],
        ]);
    }

    /**
     * GET /api/admin/ambassador-applications/{id}
     */
    public function show(AmbassadorApplication $ambassadorApplication): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new AmbassadorApplicationResource(
                $ambassadorApplication->load(['reviewer', 'statusUpdatedBy', 'notes.author', 'statusHistory.changedBy'])
            ),
        ]);
    }

    /**
     * PATCH /api/admin/ambassador-applications/{id}/status
     */
    public function updateStatus(Request $request, AmbassadorApplication $ambassadorApplication): JsonResponse
    {
        $validated = $request->validate([
            'status'                 => ['required', 'string', 'in:' . implode(',', AmbassadorApplication::allowedStatuses())],
            'reason'                 => ['nullable', 'string', 'max:1000'],
            'admin_notes'            => ['nullable', 'string', 'max:3000'],
            'interview_scheduled_at' => ['nullable', 'date', 'required_if:status,interview_scheduled'],
        ]);

        $oldStatus = $ambassadorApplication->status;
        $newStatus = $validated['status'];

        if ($oldStatus === $newStatus) {
            return response()->json(['success' => true, 'message' => 'الحالة لم تتغير.', 'data' => new AmbassadorApplicationResource($ambassadorApplication)]);
        }

        DB::transaction(function () use ($request, $ambassadorApplication, $validated, $oldStatus, $newStatus) {
            $ambassadorApplication->update([
                'status'                 => $newStatus,
                'admin_notes'            => $validated['admin_notes'] ?? $ambassadorApplication->admin_notes,
                'interview_scheduled_at' => $newStatus === 'interview_scheduled'
                    ? ($validated['interview_scheduled_at'] ?? null)
                    : $ambassadorApplication->interview_scheduled_at,
                'reviewed_by'            => $request->user()->id,
                'reviewed_at'            => now(),
                'status_updated_by'      => $request->user()->id,
                'status_updated_at'      => now(),
                'updated_by'             => $request->user()->id,
            ]);

            AmbassadorApplicationStatusHistory::create([
                'ambassador_application_id' => $ambassadorApplication->id,
                'changed_by'                => $request->user()->id,
                'from_status'               => $oldStatus,
                'to_status'                 => $newStatus,
                'reason'                    => $validated['reason'] ?? null,
                'changed_at'                => now(),
            ]);
        });

        // Notify the applicant
        NotificationService::ambassadorStatusChanged(
            $ambassadorApplication->id,
            $ambassadorApplication->full_name,
            $oldStatus,
            $newStatus,
        );

        // Send status change email
        EmailService::dispatch(
            'ambassador_status_changed',
            $ambassadorApplication->email,
            $ambassadorApplication->full_name,
            null,
            new AmbassadorStatusChangedMail(
                $ambassadorApplication->full_name,
                $newStatus,
                $validated['reason'] ?? null,
                $ambassadorApplication->interview_scheduled_at,
            ),
            'تحديث حالة طلبك — منصة EMC',
        );

        AuditLogService::log(
            'ambassador_application.status_changed',
            'AmbassadorApplication',
            $ambassadorApplication->id,
            $ambassadorApplication->full_name,
            ['status' => $oldStatus],
            ['status' => $newStatus, 'reason' => $validated['reason'] ?? null],
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الحالة بنجاح.',
            'data'    => new AmbassadorApplicationResource(
                $ambassadorApplication->fresh()->load(['reviewer', 'statusUpdatedBy', 'notes.author', 'statusHistory.changedBy'])
            ),
        ]);
    }

    /**
     * POST /api/admin/ambassador-applications/{id}/notes
     */
    public function storeNote(Request $request, AmbassadorApplication $ambassadorApplication): JsonResponse
    {
        $validated = $request->validate([
            'content'    => ['required', 'string', 'min:2', 'max:3000'],
            'is_private' => ['boolean'],
        ]);

        $note = AmbassadorApplicationNote::create([
            'ambassador_application_id' => $ambassadorApplication->id,
            'created_by'                => $request->user()->id,
            'content'                   => strip_tags($validated['content']),
            'is_private'                => $validated['is_private'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'         => $note->id,
                'content'    => $note->content,
                'is_private' => $note->is_private,
                'author'     => $request->user()->name,
                'created_at' => $note->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * DELETE /api/admin/ambassador-applications/{id}/notes/{noteId}
     */
    public function deleteNote(Request $request, AmbassadorApplication $ambassadorApplication, int $noteId): JsonResponse
    {
        $note = $ambassadorApplication->notes()->where('id', $noteId)->firstOrFail();

        // Only the author or super_admin may delete
        if ($note->created_by !== $request->user()->id && $request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => 'غير مصرح.'], 403);
        }

        $note->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف الملاحظة.']);
    }

    /**
     * GET /api/admin/ambassador-applications/{id}/cv
     */
    public function downloadCv(AmbassadorApplication $ambassadorApplication): StreamedResponse|JsonResponse
    {
        $path = $ambassadorApplication->cvStoragePath();

        if (! $path || ! Storage::disk('private')->exists($path)) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود.'], 404);
        }

        return Storage::disk('private')->download($path, 'cv_' . $ambassadorApplication->id . '.pdf');
    }

    /**
     * GET /api/admin/ambassador-applications/{id}/cv/view
     */
    public function viewCv(AmbassadorApplication $ambassadorApplication): Response|JsonResponse
    {
        $path = $ambassadorApplication->cvStoragePath();

        if (! $path || ! Storage::disk('private')->exists($path)) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود.'], 404);
        }

        return response(
            Storage::disk('private')->get($path),
            200,
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline']
        );
    }

    /**
     * GET /api/admin/ambassador-applications/{id}/student-id
     */
    public function downloadStudentId(AmbassadorApplication $ambassadorApplication): StreamedResponse|JsonResponse
    {
        $path = $ambassadorApplication->student_id_path;

        if (! $path || ! Storage::disk('private')->exists($path)) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود.'], 404);
        }

        return Storage::disk('private')->download($path, 'student_id_' . $ambassadorApplication->id);
    }

    /**
     * GET /api/admin/ambassador-applications/{id}/photo
     */
    public function viewPhoto(AmbassadorApplication $ambassadorApplication): Response|JsonResponse
    {
        $path = $ambassadorApplication->photo_path;

        if (! $path || ! Storage::disk('private')->exists($path)) {
            return response()->json(['success' => false, 'message' => 'الصورة غير موجودة.'], 404);
        }

        $mime = Storage::disk('private')->mimeType($path);

        return response(
            Storage::disk('private')->get($path),
            200,
            ['Content-Type' => $mime, 'Content-Disposition' => 'inline']
        );
    }

    /**
     * GET /api/admin/ambassador-applications/analytics
     */
    public function analytics(): JsonResponse
    {
        $base = AmbassadorApplication::where('is_draft', false);

        $total      = (clone $base)->count();
        $approved   = (clone $base)->where('status', 'approved')->count();
        $rejected   = (clone $base)->where('status', 'rejected')->count();
        $interviews = (clone $base)->where('status', 'interview_scheduled')->count();

        $byCountry = (clone $base)
            ->select('country', DB::raw('COUNT(*) as cnt'))
            ->groupBy('country')
            ->orderByDesc('cnt')
            ->limit(15)
            ->pluck('cnt', 'country');

        $byUniversity = (clone $base)
            ->select('university_name', DB::raw('COUNT(*) as cnt'))
            ->groupBy('university_name')
            ->orderByDesc('cnt')
            ->limit(15)
            ->pluck('cnt', 'university_name');

        $byMajor = (clone $base)
            ->select('major', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('major')
            ->groupBy('major')
            ->orderByDesc('cnt')
            ->limit(15)
            ->pluck('cnt', 'major');

        $byGender = (clone $base)
            ->select('gender', DB::raw('COUNT(*) as cnt'))
            ->groupBy('gender')
            ->pluck('cnt', 'gender');

        $monthly = (clone $base)
            ->select(DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"), DB::raw('COUNT(*) as cnt'))
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->get()
            ->map(fn ($r) => ['month' => $r->month, 'count' => (int) $r->cnt]);

        $conversionRate = $total > 0 ? round(($approved / $total) * 100, 1) : 0;

        return response()->json([
            'success' => true,
            'data'    => [
                'total'           => $total,
                'approved'        => $approved,
                'rejected'        => $rejected,
                'interviews'      => $interviews,
                'conversion_rate' => $conversionRate,
                'by_country'      => $byCountry,
                'by_university'   => $byUniversity,
                'by_major'        => $byMajor,
                'by_gender'       => $byGender,
                'monthly_trend'   => $monthly,
            ],
        ]);
    }

    /**
     * DELETE /api/admin/ambassador-applications/{id}
     */
    public function destroy(Request $request, AmbassadorApplication $ambassadorApplication): JsonResponse
    {
        // Only super_admin can delete
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => 'غير مصرح.'], 403);
        }

        $ambassadorApplication->update(['deleted_by' => $request->user()->id]);
        $ambassadorApplication->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف الطلب.']);
    }

    /**
     * Build the filtered ambassador applications query.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\AmbassadorApplication>
     */
    private function filteredQuery(Request $request, bool $includeStatus = true)
    {
        $university = trim((string) ($request->query('university') ?: $request->query('university_name', '')));
        $specialization = trim((string) ($request->query('specialization') ?: $request->query('major', '')));
        $country = trim((string) $request->query('country', ''));
        $city = trim((string) $request->query('city', ''));
        $gender = trim((string) $request->query('gender', ''));
        $status = trim((string) $request->query('status', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $search = trim((string) $request->query('search', ''));

        return AmbassadorApplication::query()
            ->where('is_draft', false)
            ->when($includeStatus && $status !== '' && $status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($country !== '', fn ($q) => $q->where('country', $country))
            ->when($city !== '', fn ($q) => $q->where('city', $city))
            ->when($university !== '', fn ($q) => $q->where('university_name', $university))
            ->when($specialization !== '', fn ($q) => $q->where('major', $specialization))
            ->when($gender !== '' && $gender !== 'all', fn ($q) => $q->where('gender', $gender))
            ->when($dateFrom !== '', fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo !== '', fn ($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . addcslashes($search, '%_\\') . '%';
                $q->where(function ($sub) use ($like, $search) {
                    $sub->where('full_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('mobile_phone', 'like', $like)
                        ->orWhere('university_name', 'like', $like)
                        ->orWhere('country', 'like', $like)
                        ->orWhere('city', 'like', $like)
                        ->orWhere('major', 'like', $like)
                        ->orWhere('nationality', 'like', $like)
                        ->orWhere('faculty', 'like', $like)
                        ->orWhere('uuid', 'like', $like);

                    if (ctype_digit($search)) {
                        $sub->orWhere('id', (int) $search);
                    }

                    // Support AMB-YYYY-NNNNNN reference numbers (computed, not stored).
                    if (preg_match('/^AMB-(\d{4})-0*(\d+)$/i', $search, $matches)) {
                        $sub->orWhere('id', (int) $matches[2]);
                    }
                });
            });
    }
}
