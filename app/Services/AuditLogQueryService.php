<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AuditLogQueryService
{
    private const SORTABLE = ['created_at', 'action', 'user_name', 'entity_type'];

    private const FAILED_ACTIONS = [
        'REJECT', 'REJECTED', 'LOGIN_FAILED', 'DENIED', 'ERROR', 'FAILED',
    ];

    public function base(Request $request): Builder
    {
        $query = AuditLog::query();
        $this->apply($query, $request);

        return $query;
    }

    public function apply(Builder $query, Request $request): void
    {
        $sort = $request->query('sort', 'created_at');
        if (! in_array($sort, self::SORTABLE, true)) {
            $sort = 'created_at';
        }

        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);

        if ($request->filled('action') && $request->query('action') !== 'all') {
            $action = (string) $request->query('action');
            $upper  = strtoupper($action);
            $lower  = strtolower($action);

            $query->where(function (Builder $q) use ($action, $upper, $lower) {
                $q->where('action', $action)
                    ->orWhere('action', $upper)
                    ->orWhere('action', $lower);
            });
        }

        if ($request->filled('entity_type') && $request->query('entity_type') !== 'all') {
            $entity = (string) $request->query('entity_type');
            $query->where(function (Builder $q) use ($entity) {
                $q->where('entity_type', $entity)
                    ->orWhere('entity_type', 'like', "%{$entity}%");
            });
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        if ($request->filled('role') && $request->query('role') !== 'all') {
            $query->where('user_role', $request->query('role'));
        }

        if ($request->filled('method') && $request->query('method') !== 'all') {
            $query->where('method', strtoupper((string) $request->query('method')));
        }

        if ($request->filled('ip_address')) {
            $query->where('ip_address', 'like', '%' . $request->query('ip_address') . '%');
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $this->applyStatusFilter($query, (string) $request->query('status'));
        }

        if ($request->filled('search')) {
            $this->applySearch($query, (string) $request->query('search'));
        }
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        if ($status === 'failed') {
            $query->where(function (Builder $q) {
                $q->where('action', 'like', '%fail%')
                    ->orWhereIn('action', self::FAILED_ACTIONS);
            });

            return;
        }

        if ($status === 'success') {
            $query->where(function (Builder $q) {
                $q->where('action', 'not like', '%fail%')
                    ->whereNotIn('action', self::FAILED_ACTIONS);
            });
        }
    }

    private function applySearch(Builder $query, string $term): void
    {
        $like = '%' . $term . '%';

        $query->where(function (Builder $q) use ($like, $term) {
            $q->where('user_name', 'like', $like)
                ->orWhere('user_email', 'like', $like)
                ->orWhere('user_role', 'like', $like)
                ->orWhere('entity_type', 'like', $like)
                ->orWhere('entity_name', 'like', $like)
                ->orWhere('action', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('ip_address', 'like', $like)
                ->orWhere('route', 'like', $like)
                ->orWhere('method', 'like', $like)
                ->orWhereRaw('CAST(old_values AS CHAR) LIKE ?', [$like])
                ->orWhereRaw('CAST(new_values AS CHAR) LIKE ?', [$like]);

            if (is_numeric($term)) {
                $q->orWhere('entity_id', (int) $term)
                    ->orWhere('user_id', (int) $term);
            }
        });
    }

    public function isFailedAction(string $action): bool
    {
        $upper = strtoupper($action);

        if (str_contains($upper, 'FAIL')) {
            return true;
        }

        return in_array($upper, self::FAILED_ACTIONS, true);
    }
}
