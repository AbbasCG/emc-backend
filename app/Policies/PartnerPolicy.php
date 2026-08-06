<?php

namespace App\Policies;

use App\Models\Partner;
use App\Models\User;

class PartnerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isPartner();
    }

    public function view(User $user, Partner $partner): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // Partner user can view their own org record
        if ($user->isPartner()) {
            return $partner->id === $user->partner_id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Partner $partner): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Partner $partner): bool
    {
        return $user->isAdmin();
    }
}
