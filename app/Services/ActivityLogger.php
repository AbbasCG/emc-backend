<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Centralised audit / activity log writer.
 *
 * Supports both the original call signature and the Phase 6 extended
 * signature that adds old_values / new_values for change tracking.
 */
class ActivityLogger
{
    /**
     * Log an action.
     *
     * @param  string       $action       Human-readable action name e.g. 'payment_confirmed'.
     * @param  Model|null   $subject      The primary model being acted on (sets entity_type / entity_id).
     * @param  array        $changes      Legacy field — merged into new_values for backward compat.
     * @param  Request|null $request      If provided, captures ip_address and user_agent.
     * @param  array|null   $oldValues    Snapshot before the change.
     * @param  array|null   $newValues    Snapshot after the change (overrides $changes when present).
     * @param  string|null  $entityType   Override model class name (when $subject is not a Model).
     * @param  int|null     $entityId     Override model primary key.
     */
    public static function log(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        ?Request $request = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $entityType = null,
        ?int $entityId = null,
    ): void {
        $userId = auth()->id();

        $resolvedEntityType = $entityType ?? ($subject ? get_class($subject) : null);
        $resolvedEntityId   = $entityId   ?? $subject?->getKey();
        $resolvedNewValues  = $newValues  ?? ($changes ?: null);

        ActivityLog::create([
            'user_id'     => $userId,
            'action'      => $action,
            // Legacy columns (kept for backward compat if they exist)
            'model_type'  => $resolvedEntityType,
            'model_id'    => $resolvedEntityId,
            'changes'     => $resolvedNewValues,
            // Phase 6 extended columns
            'entity_type' => $resolvedEntityType,
            'entity_id'   => $resolvedEntityId,
            'old_values'  => $oldValues,
            'new_values'  => $resolvedNewValues,
            'ip_address'  => $request?->ip(),
            'user_agent'  => $request?->userAgent(),
        ]);
    }
}
