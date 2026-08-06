<?php

namespace App\Policies;

use App\Models\ProductUpdate;
use App\Models\User;
use App\Support\Permissions;

class ProductUpdatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProductUpdate $update): bool
    {
        if ($update->status === 'published') {
            return true;
        }
        return $user->isSuperAdmin() || $user->isTechAdmin()
            || $user->hasPermission(Permissions::MANAGE_PRODUCT_UPDATES);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isTechAdmin()
            || $user->hasPermission(Permissions::MANAGE_PRODUCT_UPDATES);
    }

    public function update(User $user, ProductUpdate $update): bool
    {
        return $user->isSuperAdmin() || $user->isTechAdmin()
            || $user->hasPermission(Permissions::MANAGE_PRODUCT_UPDATES);
    }

    public function delete(User $user, ProductUpdate $update): bool
    {
        return $user->isSuperAdmin() || $user->isTechAdmin()
            || $user->hasPermission(Permissions::MANAGE_PRODUCT_UPDATES);
    }

    public function publish(User $user, ProductUpdate $update): bool
    {
        return $user->isSuperAdmin() || $user->isTechAdmin()
            || $user->hasPermission(Permissions::MANAGE_PRODUCT_UPDATES);
    }
}
