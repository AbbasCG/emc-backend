<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Consent gating only applies to volunteer-sourced rows — directly-added
        // team members (admin CRUD, no consent form ever shown) are unaffected.
        $gated = $this->requiresConsentGating();
        $photoAllowed = ! $gated || $this->photo_publication_consent === true;
        $bioAllowed   = ! $gated || $this->professional_profile_consent === true;

        $teamImage = ($photoAllowed && $this->image)
            ? (str_starts_with($this->image, 'http') ? $this->image : asset('storage/' . $this->image))
            : null;

        $userAvatar    = null;
        $userIsActive  = null;
        $userName      = null;
        $userEmail     = null;
        $userRole      = null;

        if ($this->relationLoaded('user') && $this->user) {
            $raw = $this->user->avatar;
            $userAvatar = ($photoAllowed && $raw)
                ? (str_starts_with($raw, 'http') ? $raw : asset('storage/' . $raw))
                : null;

            // Use getAttributes() so we don't mis-cast an absent column as false
            $attrs         = $this->user->getAttributes();
            $userIsActive  = array_key_exists('is_active', $attrs) ? (bool) $attrs['is_active'] : null;
            $userName      = array_key_exists('name', $attrs)      ? $attrs['name']              : null;
            $userEmail     = array_key_exists('email', $attrs)     ? $attrs['email']             : null;
            $userRole      = array_key_exists('role', $attrs)      ? $attrs['role']              : null;
        }

        return [
            'id'                 => $this->id,
            'user_id'            => $this->user_id,
            'name_ar'            => $this->name_ar,
            'name_en'            => $this->name_en,
            'position_ar'        => $this->position_ar,
            'position_en'        => $this->position_en,
            'role_key'           => $this->role_key,
            'country'            => $this->country,
            'image'              => $teamImage,
            'user_avatar_url'    => $userAvatar,
            'resolved_photo_url' => $teamImage ?? $userAvatar,
            'user_is_active'     => $userIsActive,
            'user_name'          => $userName,
            'user_email'         => $userEmail,
            'user_role'          => $userRole,
            'bio_ar'             => $bioAllowed ? $this->bio_ar : null,
            'sort_order'         => $this->sort_order,
            'is_leader'          => (bool) $this->is_leader,
            'is_executive'       => (bool) $this->is_executive,
            'is_active'          => (bool) $this->is_active,
        ];
    }
}
