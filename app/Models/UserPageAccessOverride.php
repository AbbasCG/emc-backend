<?php

namespace App\Models;

use App\Support\PageAccess\UserPageAccessState;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One explicit per-user PAGE ACCESS override (allow | deny).
 *
 * DEFAULT is the ABSENCE of a row — there is no 'default' state.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION — deliberately named
 * "PageAccessOverride", never "UserPermission", because
 * UserPermissionAssignment already exists and governs backend actions. These
 * two stores are never read together and never substitute for one another.
 *
 * Uses the Auditable trait, so every create/update/delete is recorded to
 * audit_logs with actor + old/new values (see Access History in the Phase 2C
 * report) — no bespoke history table is introduced.
 *
 * Validity, page eligibility and actor authority are enforced by
 * UserPageAccessOverrideService.
 */
class UserPageAccessOverride extends Model
{
    use Auditable;

    protected $fillable = [
        'user_id',
        'page_key',
        'state',
        'granted_by',
        'source_department_id',
    ];

    protected $casts = [
        'state' => UserPageAccessState::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function sourceDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'source_department_id');
    }
}
