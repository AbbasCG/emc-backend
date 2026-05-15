<?php

namespace App\Policies;

use App\Models\Registration;
use App\Models\User;

class RegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Registration $registration): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // Student can see their own registrations
        return ($registration->user_id && $registration->user_id === $user->id)
            || $registration->email === $user->email;
    }

    public function create(User $user): bool
    {
        return true; // public registration; auth optional
    }

    public function update(User $user, Registration $registration): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Registration $registration): bool
    {
        return $user->isAdmin();
    }
}
