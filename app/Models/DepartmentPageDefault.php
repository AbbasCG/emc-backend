<?php

namespace App\Models;

use App\Support\PageAccess\DepartmentPageAudience;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single DEFAULT page grant for a department audience (member | leader).
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION — this is deliberately named
 * "PageDefault", not "DepartmentPermission", because the project already has a
 * separate business-permission delegation system (PermissionDelegationService
 * / DelegatablePermission / UserPermissionAssignment) and the two must never
 * be confused.
 *
 * Validity and eligibility of `page_key` are enforced by
 * DepartmentPageDefaultService — the single canonical place that rule lives.
 */
class DepartmentPageDefault extends Model
{
    use Auditable;

    protected $fillable = [
        'department_id',
        'page_key',
        'audience',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'audience' => DepartmentPageAudience::class,
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function scopeForAudience(Builder $query, DepartmentPageAudience $audience): Builder
    {
        return $query->where('audience', $audience->value);
    }
}
