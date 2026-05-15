<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission(\App\Support\Permissions::VIEW_FINANCE);
    }

    public function view(User $user, Payment $payment): bool
    {
        if ($user->isAdmin() || $user->hasPermission(\App\Support\Permissions::VIEW_FINANCE)) {
            return true;
        }

        // User can view their own payment via registration
        $registration = $payment->registration;

        return $registration && (
            ($registration->user_id && $registration->user_id === $user->id)
            || $registration->email === $user->email
        );
    }

    public function update(User $user, Payment $payment): bool
    {
        return $user->isAdmin();
    }
}
