<?php

namespace App\Policies;

use App\Models\ManualRevenue;
use App\Models\User;
use App\Support\Permissions;

/**
 * Business authorization for manual revenue (receipt) vouchers.
 *
 * Page access only decides whether the screen is shown; every API action is
 * checked here. super_admin / tech_admin pass through Gate::before().
 * Whether a voucher may still be changed (draft vs posted) is a state rule
 * enforced by the controller with a 422, not a permission.
 */
class ManualRevenuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::VIEW_REVENUE);
    }

    public function view(User $user, ManualRevenue $revenue): bool
    {
        return $user->hasPermission(Permissions::VIEW_REVENUE);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::MANAGE_PAYMENTS);
    }

    public function update(User $user, ManualRevenue $revenue): bool
    {
        return $user->hasPermission(Permissions::MANAGE_PAYMENTS);
    }

    public function delete(User $user, ManualRevenue $revenue): bool
    {
        return $user->hasPermission(Permissions::MANAGE_PAYMENTS);
    }

    public function downloadAttachment(User $user, ManualRevenue $revenue): bool
    {
        return $this->view($user, $revenue);
    }
}
