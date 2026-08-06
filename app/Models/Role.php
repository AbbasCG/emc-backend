<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use Auditable;
    protected $fillable = ['name', 'display_name', 'description', 'is_system'];

    protected $casts = ['is_system' => 'boolean'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
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
}
