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

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $reports = Report::with('generatedBy')
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest('generated_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => ReportResource::collection($reports)->response()->getData(true),
        ]);
    }

    public function generate(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'type'             => 'required|in:program_report,course_report,workshop_report,financial_report,quality_report,department_report,partnership_report,hr_report',
            'reportable_type'  => 'nullable|string',
            'reportable_id'    => 'nullable|integer',
            'from'             => 'nullable|date',
            'to'               => 'nullable|date|after_or_equal:from',
        ]);

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
