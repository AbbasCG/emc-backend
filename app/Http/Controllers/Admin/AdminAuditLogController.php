<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    // GET /api/admin/audit-logs
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $query = AuditLog::query()->orderByDesc('created_at');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('actor_id')) {
            $query->where('actor_id', (int) $request->actor_id);
        }

        if ($request->filled('actor_role')) {
            $query->where('actor_role', $request->actor_role);
        }

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('actor_name', 'like', "%{$term}%")
                  ->orWhere('entity_label', 'like', "%{$term}%")
                  ->orWhere('action', 'like', "%{$term}%");
            });
        }

        $logs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $logs->map(fn ($l) => $this->format($l)),
            'meta'    => [
                'total'        => $logs->total(),
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
            ],
        ]);
    }

    // GET /api/admin/audit-logs/{auditLog}
    public function show(AuditLog $auditLog): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->format($auditLog, detailed: true),
        ]);
    }

    private function format(AuditLog $l, bool $detailed = false): array
    {
        $data = [
            'id'           => $l->id,
            'actor_id'     => $l->actor_id,
            'actor_name'   => $l->actor_name,
            'actor_role'   => $l->actor_role,
            'action'       => $l->action,
            'entity_type'  => $l->entity_type,
            'entity_id'    => $l->entity_id,
            'entity_label' => $l->entity_label,
            'metadata'     => $l->metadata,
            'ip_address'   => $l->ip_address,
            'created_at'   => $l->created_at,
        ];

        if ($detailed) {
            $data['old_values'] = $l->old_values;
            $data['new_values'] = $l->new_values;
            $data['user_agent'] = $l->user_agent;
        }

        return $data;
    }
}
