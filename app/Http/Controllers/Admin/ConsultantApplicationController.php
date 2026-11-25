<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConsultantApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** إدارة طلبات المستشارين: قائمة، تفاصيل، تحديث الحالة، ومعاينة السيرة الذاتية. */
class ConsultantApplicationController extends Controller
{
    /** Roles of the PUT .../status route (routes/api.php uses this list too) — also what `can_update_status` reports. */
    public const UPDATE_STATUS_ROLES = ['admin', 'super_admin', 'tech_admin', 'operations_manager'];

    /** Would PUT .../status be allowed for this viewer? The same rule the route's `role:` middleware enforces. */
    private function canUpdateStatus(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && \App\Http\Middleware\RoleMiddleware::passes($user, self::UPDATE_STATUS_ROLES);
    }

    /** رابط معاينة موقَّع مؤقت (ساعتان) — معاينة داخل التطبيق فقط، لا تنزيل خام. */
    private function withCvUrl(ConsultantApplication $application): array
    {
        $row = $application->toArray();
        $row['cv_view_url'] = $application->cv_file_path
            ? URL::temporarySignedRoute(
                'admin.consultant-applications.cv.view',
                now()->addHours(2),
                ['consultantApplication' => $application->id],
            )
            : null;

        return $row;
    }

    public function index(Request $request): JsonResponse
    {
        $page = ConsultantApplication::query()
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, function ($q) use ($request) {
                $s = '%' . $request->search . '%';
                $q->where(fn ($w) => $w
                    ->where('full_name', 'like', $s)
                    ->orWhere('email', 'like', $s)
                    ->orWhere('specialty', 'like', $s)
                    ->orWhere('desired_department', 'like', $s));
            })
            ->latest()
            ->paginate((int) ($request->per_page ?? 20));

        $can = $this->canUpdateStatus($request);
        $page->setCollection(
            $page->getCollection()->map(fn (ConsultantApplication $row) => $this->withCvUrl($row) + ['can_update_status' => $can]),
        );

        $counts = ConsultantApplication::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'success' => true,
            'data'    => $page,
            'meta'    => ['counts' => $counts, 'can_update_status' => $can],
        ]);
    }

    public function show(Request $request, ConsultantApplication $consultantApplication): JsonResponse
    {
        $consultantApplication->load('reviewer:id,name');

        return response()->json([
            'success' => true,
            'data'    => $this->withCvUrl($consultantApplication) + ['can_update_status' => $this->canUpdateStatus($request)],
        ]);
    }

    public function updateStatus(Request $request, ConsultantApplication $consultantApplication): JsonResponse
    {
        $data = $request->validate([
            'status'        => 'required|in:' . implode(',', ConsultantApplication::STATUSES),
            'reviewer_note' => 'nullable|string|max:2000',
        ]);

        $consultantApplication->update([
            'status'        => $data['status'],
            'reviewer_note' => $data['reviewer_note'] ?? $consultantApplication->reviewer_note,
            'reviewed_by'   => $request->user()->id,
            'reviewed_at'   => now(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->withCvUrl($consultantApplication->fresh()->load('reviewer:id,name')),
        ]);
    }

    /** معاينة السيرة الذاتية عبر رابط موقَّع — نفس سياسة خصوصية سير المتطوعين. */
    public function viewCv(ConsultantApplication $consultantApplication): StreamedResponse|JsonResponse
    {
        $path = $consultantApplication->cv_file_path;

        if (! $path || ! Storage::disk('private')->exists($path)) {
            return response()->json(['success' => false, 'message' => 'CV file not found.'], 404);
        }

        return Storage::disk('private')->response($path);
    }
}
