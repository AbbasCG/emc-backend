<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogQueryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AdminAuditLogController extends Controller
{
    public function __construct(private AuditLogQueryService $queryService) {}

    private const ALLOWED_PER_PAGE = [25, 50, 100];
    private const DEFAULT_PER_PAGE = 50;

    // GET /api/admin/audit-logs
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        if (! in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        $logs = $this->queryService->base($request)->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $logs->map(fn ($l) => $this->format($l)),
            'meta'    => $this->paginationMeta($logs),
        ]);
    }

    // GET /api/admin/audit-logs/stats
    public function stats(Request $request): JsonResponse
    {
        try {
            $base = $this->queryService->base($request);

            $todayStart  = now()->startOfDay();
            $weekStart   = now()->startOfWeek();
            $monthStart  = now()->startOfMonth();

            $total = (clone $base)->count();

            $today = (clone $base)->where('created_at', '>=', $todayStart)->count();
            $week  = (clone $base)->where('created_at', '>=', $weekStart)->count();
            $month = (clone $base)->where('created_at', '>=', $monthStart)->count();

            $uniqueUsers = (clone $base)
                ->whereNotNull('user_id')
                ->distinct('user_id')
                ->count('user_id');

            $failed = (clone $base)->where(function ($q) {
                $q->where('action', 'like', '%fail%')
                    ->orWhereIn('action', ['REJECT', 'REJECTED', 'LOGIN_FAILED', 'DENIED', 'ERROR', 'FAILED']);
            })->count();

            $successful = max(0, $total - $failed);

            $topEntity = (clone $base)
                ->select('entity_type', DB::raw('COUNT(*) as total'))
                ->whereNotNull('entity_type')
                ->where('entity_type', '!=', '')
                ->groupBy('entity_type')
                ->orderByDesc('total')
                ->first();

            $mostActiveUser = (clone $base)
                ->select('user_name', 'user_id', DB::raw('COUNT(*) as total'))
                ->whereNotNull('user_name')
                ->where('user_name', '!=', '')
                ->groupBy('user_name', 'user_id')
                ->orderByDesc('total')
                ->first();

            $mostCommonAction = (clone $base)
                ->select('action', DB::raw('COUNT(*) as total'))
                ->whereNotNull('action')
                ->groupBy('action')
                ->orderByDesc('total')
                ->first();

            return response()->json([
                'success' => true,
                'data'    => [
                    'total'              => $total,
                    'today'              => $today,
                    'this_week'          => $week,
                    'this_month'         => $month,
                    'unique_users'       => $uniqueUsers,
                    'failed_operations'  => $failed,
                    'successful_operations' => $successful,
                    'top_entity'         => $topEntity?->entity_type,
                    'most_active_user'   => $mostActiveUser?->user_name,
                    'most_common_action' => $mostCommonAction?->action,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'تعذّر تحميل إحصاءات السجل.',
            ], 500);
        }
    }

    // GET /api/admin/audit-logs/export
    public function export(Request $request): StreamedResponse|JsonResponse|\Illuminate\Http\Response
    {
        $format   = strtolower((string) $request->query('format', 'csv'));
        $maxRows  = 5000;
        $chunkSize = 500;

        $baseQuery = $this->queryService->base($request)->limit($maxRows);

        if ($format === 'json') {
            $rows = $baseQuery->get()->map(fn ($l) => $this->format($l, detailed: true));

            return response()->json([
                'success' => true,
                'data'    => $rows,
                'meta'    => ['exported' => $rows->count(), 'max_rows' => $maxRows],
            ]);
        }

        if ($format === 'pdf') {
            $rows = $baseQuery->get()->map(fn ($l) => $this->format($l, detailed: true));

            $html = view('exports.audit-logs-pdf', [
                'rows'     => $rows,
                'exported' => $rows->count(),
                'filters'  => $request->query(),
            ])->render();

            $pdf = Pdf::loadHTML($html)->setPaper('a4', 'landscape');

            return $pdf->download('audit-logs-' . now()->format('Ymd_His') . '.pdf');
        }

        $filename = 'audit-logs-' . now()->format('Ymd_His') . ($format === 'xlsx' ? '.xls' : '.csv');
        $mime     = $format === 'xlsx'
            ? 'application/vnd.ms-excel; charset=UTF-8'
            : 'text/csv; charset=UTF-8';

        // Chunked stream — never loads all rows into PHP memory at once.
        $query = $this->queryService->base($request)->limit($maxRows);

        return response()->streamDownload(function () use ($query, $chunkSize) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                'ID', 'User', 'Email', 'Role', 'Action', 'Entity Type', 'Entity ID',
                'Entity Name', 'Description', 'IP', 'Method', 'Route', 'Created At',
            ]);

            $query->chunk($chunkSize, function ($logs) use ($handle) {
                foreach ($logs as $l) {
                    $row = $this->format($l, detailed: false);
                    fputcsv($handle, [
                        $row['id'],
                        $row['actor_name'],
                        $row['user']['email'] ?? '',
                        $row['actor_role'],
                        $row['action'],
                        $row['entity_type'],
                        $row['entity_id'] ?? '',
                        $row['entity_name'] ?? '',
                        $row['description'] ?? '',
                        $row['ip_address'] ?? '',
                        $row['method'] ?? '',
                        $row['route'] ?? '',
                        $row['created_at'] ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => $mime]);
    }

    // GET /api/admin/audit-logs/{auditLog}
    public function show(AuditLog $auditLog): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->format($auditLog, detailed: true),
        ]);
    }

    private function paginationMeta($paginator): array
    {
        return [
            'total'        => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'from'         => $paginator->firstItem(),
            'to'           => $paginator->lastItem(),
        ];
    }

    /** Lightweight row for paginated list — no heavy JSON columns. */
    private function format(AuditLog $l, bool $detailed = false): array
    {
        $meta = $l->action_meta;

        $data = [
            'id'           => $l->id,
            'user'         => [
                'id'    => $l->user_id,
                'name'  => $l->user_name,
                'email' => $l->user_email,
                'role'  => $l->user_role,
            ],
            'actor_name'   => $l->user_name,
            'actor_role'   => $l->user_role,
            'entity_label' => $l->entity_name,

            'action'       => $l->action,
            'action_label' => $meta['label'],
            'action_color' => $meta['color'],
            'action_icon'  => $meta['icon'],

            'entity_type'  => $l->entity_type,
            'entity_id'    => $l->entity_id,
            'entity_name'  => $l->entity_name,
            'description'  => $l->description,

            'ip_address'   => $l->ip_address,
            'route'        => $l->route,
            'method'       => $l->method,
            'created_at'   => $l->created_at?->format('Y-m-d H:i:s'),
        ];

        if ($detailed) {
            $data['old_values']     = $l->old_values;
            $data['new_values']     = $l->new_values;
            $data['changed_fields'] = $l->changed_fields;
            $data['metadata']       = $l->metadata;
            $data['user_agent']     = $l->user_agent;
        }

        return $data;
    }
}
