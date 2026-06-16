<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogService
{
    /** Fields never logged, regardless of context. */
    public const SENSITIVE_FIELDS = [
        'password', 'remember_token', 'token', 'api_token',
        'access_token', 'refresh_token', 'secret', 'otp',
        'two_factor_secret', 'two_factor_recovery_codes',
    ];

    /** Timestamp fields skipped unless they are the ONLY change. */
    private const TIMESTAMP_FIELDS = ['updated_at', 'created_at', 'deleted_at'];

    // ── Public convenience methods ────────────────────────────────────────────

    public static function logCreate(Model $model, ?string $description = null): void
    {
        $label = static::resolveLabel($model);
        static::log(
            action:      'CREATE',
            entityType:  class_basename($model),
            entityId:    $model->getKey(),
            entityName:  $label,
            newValues:   static::sanitize($model->toArray()),
            description: $description ?? ('Created ' . class_basename($model) . ' "' . $label . '"'),
        );
    }

    public static function logUpdate(
        Model $model,
        array $oldValues,
        array $newValues,
        ?string $description = null
    ): void {
        $changedFields = array_keys(array_diff_assoc($newValues, $oldValues));
        if (empty($changedFields)) {
            return;
        }

        $label = static::resolveLabel($model);
        static::log(
            action:        'UPDATE',
            entityType:    class_basename($model),
            entityId:      $model->getKey(),
            entityName:    $label,
            oldValues:     static::sanitize(array_intersect_key($oldValues, array_flip($changedFields))),
            newValues:     static::sanitize(array_intersect_key($newValues, array_flip($changedFields))),
            changedFields: $changedFields,
            description:   $description ?? ('Updated ' . class_basename($model) . ' "' . $label . '"'),
        );
    }

    public static function logDelete(Model $model, ?string $description = null): void
    {
        $label = static::resolveLabel($model);
        static::log(
            action:      'DELETE',
            entityType:  class_basename($model),
            entityId:    $model->getKey(),
            entityName:  $label,
            oldValues:   static::sanitize($model->toArray()),
            description: $description ?? ('Deleted ' . class_basename($model) . ' "' . $label . '"'),
        );
    }

    public static function logAction(
        string  $action,
        ?Model  $model = null,
        ?string $description = null,
        array   $meta = [],
        ?Request $request = null,
    ): void {
        static::log(
            action:      strtoupper($action),
            entityType:  $model ? class_basename($model) : null,
            entityId:    $model?->getKey(),
            entityName:  $model ? static::resolveLabel($model) : null,
            description: $description,
            extra:       $meta ?: null,
            request:     $request,
        );
    }

    public static function logLogin(User $user, ?Request $request = null): void
    {
        $req = $request ?? request();
        AuditLog::create([
            'user_id'    => $user->id,
            'user_name'  => $user->name,
            'user_email' => $user->email,
            'user_role'  => $user->role,
            'action'     => 'LOGIN',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'entity_name' => $user->name,
            'description' => $user->name . ' logged in',
            'ip_address'  => $req->ip(),
            'user_agent'  => $req->userAgent(),
            'route'       => $req->route()?->getName() ?? $req->path(),
            'method'      => $req->method(),
        ]);
    }

    public static function logLogout(User $user, ?Request $request = null): void
    {
        $req = $request ?? request();
        AuditLog::create([
            'user_id'    => $user->id,
            'user_name'  => $user->name,
            'user_email' => $user->email,
            'user_role'  => $user->role,
            'action'     => 'LOGOUT',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'entity_name' => $user->name,
            'description' => $user->name . ' logged out',
            'ip_address'  => $req->ip(),
            'user_agent'  => $req->userAgent(),
            'route'       => $req->route()?->getName() ?? $req->path(),
            'method'      => $req->method(),
        ]);
    }

    public static function logDownload(Model $model, ?string $fileName = null, ?Request $request = null): void
    {
        $label = $fileName ?? static::resolveLabel($model);
        static::log(
            action:      'DOWNLOAD',
            entityType:  class_basename($model),
            entityId:    $model->getKey(),
            entityName:  $label,
            description: 'Downloaded ' . ($fileName ? '"' . $fileName . '"' : class_basename($model) . ' file'),
            request:     $request,
        );
    }

    public static function logExport(string $entityType, ?string $description = null, ?Request $request = null): void
    {
        static::log(
            action:      'EXPORT',
            entityType:  $entityType,
            description: $description ?? ('Exported ' . $entityType . ' data'),
            request:     $request,
        );
    }

    public static function logImport(string $entityType, ?string $description = null, ?Request $request = null): void
    {
        static::log(
            action:      'IMPORT',
            entityType:  $entityType,
            description: $description ?? ('Imported ' . $entityType . ' data'),
            request:     $request,
        );
    }

    // ── Legacy backward-compatible methods ────────────────────────────────────

    /**
     * Low-level log entry. Kept for existing controller calls.
     * New code should use the typed helpers above.
     */
    public static function log(
        string  $action,
        ?string $entityType   = null,
        ?int    $entityId     = null,
        ?string $entityName   = null,
        ?array  $oldValues    = null,
        ?array  $newValues    = null,
        ?array  $extra        = null,
        ?Request $request     = null,
        ?string  $description = null,
        ?array   $changedFields = null,
    ): void {
        $req = $request ?? request();

        $isImpersonating = (bool) $req->attributes->get('is_impersonating', false);

        if ($isImpersonating) {
            $impersonatorId = $req->attributes->get('impersonator_id');
            $actor = $impersonatorId ? \App\Models\User::find($impersonatorId) : null;
            $extra = array_merge($extra ?? [], [
                'impersonated_as_id'   => $req->user()?->id,
                'impersonated_as_name' => $req->user()?->name,
            ]);
        } else {
            $actor = $req->user();
        }

        AuditLog::create([
            'user_id'       => $actor?->id,
            'user_name'     => $actor?->name,
            'user_email'    => $actor?->email,
            'user_role'     => $actor?->role,
            'action'        => $action,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'entity_name'   => $entityName,
            'description'   => $description,
            'old_values'    => $oldValues ? static::sanitize($oldValues) : null,
            'new_values'    => $newValues ? static::sanitize($newValues) : null,
            'changed_fields' => $changedFields,
            'metadata'      => $extra ?: null,
            'ip_address'    => $req->ip(),
            'user_agent'    => $req->userAgent(),
            'route'         => $req->route()?->getName() ?? $req->path(),
            'method'        => $req->method(),
        ]);
    }

    /** Convenience wrapper for Model instances (backward compat). */
    public static function logModel(
        string   $action,
        Model    $model,
        ?array   $oldValues = null,
        ?array   $newValues = null,
        ?array   $extra     = null,
        ?Request $request   = null,
    ): void {
        $label = static::resolveLabel($model);

        static::log(
            action:     $action,
            entityType: class_basename($model),
            entityId:   $model->getKey(),
            entityName: $label,
            oldValues:  $oldValues,
            newValues:  $newValues,
            extra:      $extra,
            request:    $request,
        );
    }

    /** Return only fields that actually changed, skipping sensitive + timestamp fields. */
    public static function changedFields(Model $model): array
    {
        $dirty = $model->getDirty();
        $skip  = array_merge(static::SENSITIVE_FIELDS, static::TIMESTAMP_FIELDS);

        return collect($dirty)
            ->reject(fn ($v, $k) => in_array($k, $skip, true))
            ->toArray();
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private static function resolveLabel(Model $model): string
    {
        return (string) ($model->name ?? $model->title ?? $model->email ?? $model->getKey());
    }

    /** Strip sensitive field values before persisting. */
    private static function sanitize(array $values): array
    {
        return collect($values)
            ->reject(fn ($v, $k) => in_array($k, static::SENSITIVE_FIELDS, true))
            ->toArray();
    }
}
