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

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->user_id);
        }

        if ($request->filled('role')) {
            $query->where('user_role', $request->role);
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
                $q->where('user_name',   'like', "%{$term}%")
                  ->orWhere('user_email', 'like', "%{$term}%")
                  ->orWhere('entity_name', 'like', "%{$term}%")
                  ->orWhere('action',     'like', "%{$term}%")
                  ->orWhere('description', 'like', "%{$term}%");
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
        $meta = $l->action_meta;

        $data = [
            'id'             => $l->id,
            'user'           => [
                'id'    => $l->user_id,
                'name'  => $l->user_name,
                'email' => $l->user_email,
                'role'  => $l->user_role,
            ],
            // Legacy field aliases for backward-compat with existing frontend
            'actor_name'     => $l->user_name,
            'actor_role'     => $l->user_role,
            'entity_label'   => $l->entity_name,

            'action'         => $l->action,
            'action_label'   => $meta['label'],
            'action_color'   => $meta['color'],
            'action_icon'    => $meta['icon'],

            'entity_type'    => $l->entity_type,
            'entity_id'      => $l->entity_id,
            'entity_name'    => $l->entity_name,
            'description'    => $l->description,

            // Diff fields — always returned so the list view can render changes inline
            'old_values'     => $l->old_values,
            'new_values'     => $l->new_values,
            'changed_fields' => $l->changed_fields,

            'ip_address'     => $l->ip_address,
            'route'          => $l->route,
            'method'         => $l->method,
            'created_at'     => $l->created_at?->format('Y-m-d H:i:s'),
        ];

        if ($detailed) {
            $data['metadata']   = $l->metadata;
            $data['user_agent'] = $l->user_agent;
        }

        return $data;
    }
}
