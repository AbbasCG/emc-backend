<?php

namespace App\Traits;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;

/**
 * Automatically logs CREATE / UPDATE / DELETE events for any Eloquent model.
 *
 * Usage:  use Auditable;
 *
 * To skip automatic logging for a single operation wrap it with:
 *   ModelClass::withoutAudit(fn () => $model->update($data));
 */
trait Auditable
{
    /** Per-class flag to suspend automatic auditing temporarily. */
    public static bool $auditingEnabled = true;

    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            if (! static::$auditingEnabled) {
                return;
            }

            $label = static::resolveAuditLabel($model);
            static::writeAuditLog([
                'action'      => 'CREATE',
                'entity_type' => class_basename($model),
                'entity_id'   => $model->getKey(),
                'entity_name' => $label,
                'new_values'  => static::sanitizeAuditValues($model->toArray()),
                'description' => 'Created ' . class_basename($model) . ' "' . $label . '"',
            ]);
        });

        static::updated(function (Model $model) {
            if (! static::$auditingEnabled) {
                return;
            }

            // Determine what actually changed (skip timestamps + sensitive fields)
            $skip          = array_merge(AuditLogService::SENSITIVE_FIELDS, ['updated_at', 'created_at', 'deleted_at']);
            $changedFields = collect($model->getChanges())
                ->keys()
                ->reject(fn ($k) => in_array($k, $skip, true))
                ->values()
                ->all();

            // Nothing meaningful changed
            if (empty($changedFields)) {
                return;
            }

            $original = $model->getOriginal();
            $changes  = $model->getChanges();

            $old = static::sanitizeAuditValues(array_intersect_key($original, array_flip($changedFields)));
            $new = static::sanitizeAuditValues(array_intersect_key($changes, array_flip($changedFields)));

            $label = static::resolveAuditLabel($model);
            static::writeAuditLog([
                'action'         => 'UPDATE',
                'entity_type'    => class_basename($model),
                'entity_id'      => $model->getKey(),
                'entity_name'    => $label,
                'old_values'     => $old,
                'new_values'     => $new,
                'changed_fields' => $changedFields,
                'description'    => 'Updated ' . class_basename($model) . ' "' . $label . '"',
            ]);
        });

        static::deleted(function (Model $model) {
            if (! static::$auditingEnabled) {
                return;
            }

            $label = static::resolveAuditLabel($model);
            static::writeAuditLog([
                'action'      => 'DELETE',
                'entity_type' => class_basename($model),
                'entity_id'   => $model->getKey(),
                'entity_name' => $label,
                'old_values'  => static::sanitizeAuditValues($model->toArray()),
                'description' => 'Deleted ' . class_basename($model) . ' "' . $label . '"',
            ]);
        });
    }

    /**
     * Run $callback without triggering audit events for this model class.
     */
    public static function withoutAudit(callable $callback): mixed
    {
        $previous              = static::$auditingEnabled;
        static::$auditingEnabled = false;
        try {
            return $callback();
        } finally {
            static::$auditingEnabled = $previous;
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function writeAuditLog(array $payload): void
    {
        try {
            $req = request();

            $isImpersonating = (bool) $req?->attributes->get('is_impersonating', false);

            if ($isImpersonating) {
                $impersonatorId = $req->attributes->get('impersonator_id');
                $actor = $impersonatorId ? \App\Models\User::find($impersonatorId) : null;
            } else {
                $actor = $req?->user();
            }

            AuditLog::create(array_merge([
                'user_id'    => $actor?->id,
                'user_name'  => $actor?->name,
                'user_email' => $actor?->email,
                'user_role'  => $actor?->role,
                'ip_address' => $req?->ip(),
                'user_agent' => $req?->userAgent(),
                'route'      => $req?->route()?->getName() ?? $req?->path(),
                'method'     => $req?->method(),
            ], $payload));
        } catch (\Throwable $e) {
            // Never let audit failure break the main request
            \Illuminate\Support\Facades\Log::error('Audit log write failed: ' . $e->getMessage(), [
                'payload' => $payload,
            ]);
        }
    }

    private static function resolveAuditLabel(Model $model): string
    {
        return (string) ($model->name ?? $model->title ?? $model->email ?? $model->getKey());
    }

    private static function sanitizeAuditValues(array $values): array
    {
        return collect($values)
            ->reject(fn ($v, $k) => in_array($k, AuditLogService::SENSITIVE_FIELDS, true))
            ->toArray();
    }
}
