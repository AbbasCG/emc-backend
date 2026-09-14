<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use Auditable;
    protected $fillable = ['name', 'display_name', 'description', 'is_system'];

    protected $casts = ['is_system' => 'boolean'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    /**
     * Phase 2B default PAGE ACCESS for this role. Deliberately separate from
     * permissions() above: page exposure, never business authorization — see
     * RolePageDefault and RolePageDefaultService.
     */
    public function pageDefaults(): HasMany
    {
        return $this->hasMany(RolePageDefault::class, 'role_id');
    }

    public function grantPermission(string|Permission $permission): void
    {
        $perm = $permission instanceof Permission
            ? $permission
            : Permission::query()->where('name', $permission)->firstOrFail();

        $this->permissions()->syncWithoutDetaching([$perm->id]);
    }

    public function revokePermission(string|Permission $permission): void
    {
        $perm = $permission instanceof Permission
            ? $permission
            : Permission::query()->where('name', $permission)->first();

        if ($perm) {
            $this->permissions()->detach($perm->id);
        }
    }

    public function syncPermissions(array $permissionNames): void
    {
        $ids = Permission::query()->whereIn('name', $permissionNames)->pluck('id');
        $this->permissions()->sync($ids);
    }

    /**
     * Route-model binding: support both numeric ID and role name.
     * e.g. /admin/roles/1/permissions  OR  /admin/roles/super_admin/permissions
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        // Try by name first (most common from frontend), then fall back to id
        return $this->where('name', $value)->first()
            ?? (is_numeric($value) ? $this->find((int) $value) : null);
    }

    /** Convenience: find role by slug name or fail. */
    public static function findByName(string $name): self
    {
        return static::query()->where('name', $name)->firstOrFail();
    }

    /**
     * Canonical list of every role slug a user account may be assigned —
     * the SINGLE source of truth for role-assignment validation (see
     * AdminUserController::update()/store() and UserRoleController::
     * assignRole()/roleOptions()). Reads the `roles` table (seeded by
     * RoleSeeder) instead of a hardcoded array, so a role only needs to
     * exist here once — via the roles table — to become assignable
     * everywhere, instead of requiring the same slug to be manually added
     * to every controller's own duplicated allowlist (the exact drift that
     * caused role assignment to 422 for roles the table already had).
     *
     * 'teacher' is added explicitly: it is a deliberately unseeded legacy
     * slug that RoleMiddleware still accepts as a synonym for 'instructor'
     * for backward compatibility with older accounts, not a real row in
     * the roles table.
     */
    public static function assignableSlugs(): array
    {
        return static::query()->pluck('name')->push('teacher')->unique()->values()->all();
    }
}
