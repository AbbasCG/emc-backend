<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReportResource;
use App\Models\Report;
use App\Services\AuditLogService;
use App\Services\ReportGeneratorService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly ReportGeneratorService $generator) {}

    /**
     * Return the set of report types this user's role is allowed to generate.
     * super_admin / tech_admin / admin have no restriction.
     */
    private function allowedTypesForUser(\App\Models\User $user): ?array
    {
        if ($user->isAdmin()) {
            return null; // null = unrestricted
        }

        return match (true) {
            $user->hasRole('programs_manager') => [
                'program_report', 'course_report', 'workshop_report',
                'learning_path_report', 'enrollment_report',
            ],
            $user->hasRole('finance_manager') => [
                'financial_report', 'revenue_report', 'program_report',
            ],
            $user->hasRole('hr_manager') => [
                'hr_report', 'staff_report',
            ],
            $user->hasRole('quality_manager') => [
                'quality_report', 'department_report',
            ],
            default => [],
        };
    }

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $allowed = $this->allowedTypesForUser($request->user());

        $reports = Report::with('generatedBy')
            ->when($allowed !== null, fn ($q) => $q->whereIn('type', $allowed))
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest('generated_at')
            ->paginate(20);

        return response()->json([
            'success'       => true,
            'data'          => ReportResource::collection($reports)->response()->getData(true),
            'allowed_types' => $allowed, // tells the frontend which generate options to show
        ]);
    }

    public function generate(Request $request): \Illuminate\Http\JsonResponse
    {
        $allTypes = [
            'program_report', 'course_report', 'workshop_report', 'learning_path_report',
            'enrollment_report', 'financial_report', 'revenue_report', 'quality_report',
            'department_report', 'partnership_report', 'hr_report', 'staff_report',
        ];

        $data = $request->validate([
            'type'             => 'required|in:' . implode(',', $allTypes),
            'reportable_type'  => 'nullable|string',
            'reportable_id'    => 'nullable|integer',
            'from'             => 'nullable|date',
            'to'               => 'nullable|date|after_or_equal:from',
        ]);

        $allowed = $this->allowedTypesForUser($request->user());
        if ($allowed !== null && ! in_array($data['type'], $allowed, true)) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بإنشاء هذا النوع من التقارير.',
            ], 403);
        }

        $report = $this->generator->generate($data['type'], $data, $request->user()->id);

        AuditLogService::logExport(
            $data['type'],
            $request->user()->name . ' exported ' . $data['type'],
            $request
        );

        return response()->json([
            'success' => true,
            'message' => 'Report generated.',
            'data'    => new ReportResource($report->load('generatedBy')),
        ], 201);
    }

    public function show(Report $report): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new ReportResource($report->load('generatedBy')),
        ]);
    }

    public function destroy(Report $report): \Illuminate\Http\JsonResponse
    {
        $report->delete();

        return response()->json(['success' => true, 'message' => 'Report deleted.']);
    }

    public function exportPdf(Report $report): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'PDF export is not yet available. Install a package such as barryvdh/laravel-dompdf and implement this endpoint.',
            'report'  => ['id' => $report->id, 'title' => $report->title],
        ], 501);
    }

    public function exportExcel(Report $report): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Excel export is not yet available. Install maatwebsite/excel and implement this endpoint.',
            'report'  => ['id' => $report->id, 'title' => $report->title],
        ], 501);
    }
}
