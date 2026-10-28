<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VolunteerHrProfile;
use App\Support\Permissions;

/**
 * Staff actions on a volunteer's HR profile. super_admin and tech_admin never reach these methods (Gate::before).
 * The route group already limits the HR portal to hr_manager / admin / super_admin / tech_admin; this decides who may CHANGE a
 * profile: the people who manage volunteers.
 */
class VolunteerHrProfilePolicy
{
    public function hrUpdate(User $user, VolunteerHrProfile $profile): bool
    {
        return $user->hasPermission(Permissions::MANAGE_VOLUNTEERS);
    }
}
