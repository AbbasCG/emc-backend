<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogService
{
    /**
     * Record an audit event.
     *
     * Impersonation-aware: if the current request carries an impersonation
     * session the real actor (super_admin) is logged, not the impersonated user.
     * The impersonated user's id is stored in metadata.impersonated_as_id.
     *
     * @param  string       $action       e.g. 'created', 'updated', 'deleted', 'status_changed', 'role_changed', 'approved', 'rejected'
     * @param  string       $entityType   Short class name e.g. 'User', 'TeamMember', 'Workshop'
     * @param  int|null     $entityId
     * @param  string|null  $entityLabel  Human-readable name/title for the entity
     * @param  array|null   $oldValues    State before change (omit on create)
     * @param  array|null   $newValues    State after change  (omit on delete)
     * @param  array|null   $extra        Additional metadata key/value pairs
     * @param  Request|null $request      Used for ip/user-agent; falls back to request()
     */
    public static function log(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?string $entityLabel = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $extra = null,
        ?Request $request = null,
    ): void {
        $req = $request ?? request();

        $isImpersonating = (bool) $req->attributes->get('is_impersonating', false);

        if ($isImpersonating) {
            // Real actor = super_admin who started the impersonation
            $impersonatorId = $req->attributes->get('impersonator_id');
            $actor = $impersonatorId ? User::find($impersonatorId) : null;
            $extra = array_merge($extra ?? [], [
                'impersonated_as_id'   => $req->user()?->id,
                'impersonated_as_name' => $req->user()?->name,
            ]);
        } else {
            $actor = $req->user();
        }

        AuditLog::create([
            'actor_id'     => $actor?->id,
            'actor_name'   => $actor?->name,
            'actor_role'   => $actor?->role,
            'action'       => $action,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'entity_label' => $entityLabel,
            'old_values'   => $oldValues,
            'new_values'   => $newValues,
            'metadata'     => $extra ?: null,
            'ip_address'   => $req->ip(),
            'user_agent'   => $req->userAgent(),
        ]);
    }

    /**
     * Convenience wrapper for Model instances — extracts type, id, and label automatically.
     * Label resolution: tries name → title → email → id.
     */
    public static function logModel(
        string $action,
        Model $model,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $extra = null,
        ?Request $request = null,
    ): void {
        $label = $model->name ?? $model->title ?? $model->email ?? (string) $model->getKey();

        static::log(
            action:      $action,
            entityType:  class_basename($model),
            entityId:    $model->getKey(),
            entityLabel: $label,
            oldValues:   $oldValues,
            newValues:   $newValues,
            extra:       $extra,
            request:     $request,
        );
    }

    /**
     * Capture only the fields that actually changed, excluding password and timestamps.
     */
    public static function changedFields(Model $model): array
    {
        $skip = ['password', 'remember_token', 'updated_at', 'created_at'];

        return collect($model->getDirty())
            ->reject(fn ($v, $k) => in_array($k, $skip, true))
            ->toArray();
    }
}
