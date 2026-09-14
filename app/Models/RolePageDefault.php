<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single DEFAULT page grant for a role.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION — deliberately named "PageDefault",
 * never "RolePermission", because `permission_role` already exists and means
 * something entirely different (what backend actions the role may perform).
 * A row here answers only "is this dashboard capability part of this role's
 * default page access".
 *
 * Phase 2B is POSITIVE DEFAULTS ONLY — there is deliberately no deny/state
 * column. Per-user ALLOW/DENY belongs to Phase 2C.
 *
 * Validity and eligibility of `page_key` are enforced by RolePageDefaultService.
 */
class RolePageDefault extends Model
{
    use Auditable;

    protected $fillable = [
        'role_id',
        'page_key',
        'created_by',
        'updated_by',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
