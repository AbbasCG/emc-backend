<?php

namespace App\Policies;

use App\Models\ManualPayment;
use App\Models\User;

class ManualPaymentPolicy
{
    private function isFinanceRole(User $user): bool
    {
        $role = $user->role ?? $user->roles?->pluck('name')?->first();
        return in_array($role, ['finance_manager', 'admin', 'super_admin', 'tech_admin'], true);
    }

    public function viewAny(User $user): bool
    {
        return $this->isFinanceRole($user);
    }

    public function view(User $user, ManualPayment $mp): bool
    {
        return $this->isFinanceRole($user);
    }

    public function create(User $user): bool
    {
        return $this->isFinanceRole($user);
    }

    public function update(User $user, ManualPayment $mp): bool
    {
        return $this->isFinanceRole($user) && $mp->status === 'pending_review';
    }

    public function confirm(User $user, ManualPayment $mp): bool
    {
        return $this->isFinanceRole($user) && $mp->status === 'pending_review';
    }

    public function reject(User $user, ManualPayment $mp): bool
    {
        return $this->isFinanceRole($user) && $mp->status === 'pending_review';
    }

    public function cancel(User $user, ManualPayment $mp): bool
    {
        return $this->isFinanceRole($user) && in_array($mp->status, ['pending_review'], true);
    }
}
