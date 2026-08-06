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
     * Query params: status, country, university_name, major, gender, search, date_from, date_to, per_page, page
     */
    public function index(Request $request): JsonResponse
    {
        $base = AmbassadorApplication::query()
            ->where('is_draft', false)
            ->when($request->filled('status'),           fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('country'),          fn ($q) => $q->where('country', $request->country))
            ->when($request->filled('university_name'),  fn ($q) => $q->where('university_name', 'like', "%{$request->university_name}%"))
            ->when($request->filled('major'),            fn ($q) => $q->where('major', 'like', "%{$request->major}%"))
            ->when($request->filled('gender'),           fn ($q) => $q->where('gender', $request->gender))
            ->when($request->filled('date_from'),        fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'),          fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->search;
                $q->where(function ($sub) use ($term) {
                    $sub->where('full_name',       'like', "%{$term}%")
                        ->orWhere('email',          'like', "%{$term}%")
                        ->orWhere('mobile_phone',   'like', "%{$term}%")
                        ->orWhere('university_name','like', "%{$term}%")
                        ->orWhere('major',          'like', "%{$term}%");
                });
            });

        // Statistics (filter-aware, status-agnostic)
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

        // Country breakdown for analytics
        $byCountry = (clone $base)
            ->select('country', DB::raw('COUNT(*) as cnt'))
            ->groupBy('country')
            ->orderByDesc('cnt')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['country' => $r->country, 'count' => (int) $r->cnt]);

        // Monthly trend
        $monthlyTrend = (clone $base)
            ->select(DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"), DB::raw('COUNT(*) as cnt'))
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->get()
            ->map(fn ($r) => ['month' => $r->month, 'count' => (int) $r->cnt]);

        $perPage  = min((int) $request->query('per_page', 20), 100);
        $paginated = (clone $base)
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
}
