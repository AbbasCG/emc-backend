<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $departmentId = null;
        if ($this->relationLoaded('departments') && $this->departments->count() > 0) {
            $departmentId = $this->departments->where('pivot.is_primary', true)->first()->id ?? $this->departments->first()->id;
        } elseif ($this->department) {
            if (is_numeric($this->department)) {
                $departmentId = (int) $this->department;
            } else {
                $dept = \App\Models\Department::where('slug', $this->department)->first();
                if ($dept) $departmentId = $dept->id;
            }
        }

        return [
            'id'                         => $this->id,
            'name'                       => $this->name,
            'email'                      => $this->email,
            'phone'                      => $this->phone,
            'phone_country_code'         => $this->phone_country_code,
            'role'                       => $this->effectiveRole(),
            'department'                 => $this->department,
            'department_id'              => $departmentId,
            'is_active'                  => $this->is_active ?? true,
            'city'                       => $this->city,
            'gender'                     => $this->gender,
            'country'                    => $this->country,
            'country_code'               => $this->country_code,
            'how_did_you_hear_about_us'  => $this->how_did_you_hear_about_us,
            'profile_photo_url'          => $this->avatar_url,
            'avatar_url'                 => $this->avatar_url,
            'instructor_bio'             => $this->when(
                $this->isTeacher(),
                fn () => $this->relationLoaded('instructorProfile') ? $this->instructorProfile?->bio : null,
            ),
            'email_verified_at'          => $this->email_verified_at,
            'email_verified'             => $this->email_verified_at !== null,
            'last_login_at'              => $this->last_login_at,
            'created_at'                 => $this->created_at,
            'updated_at'                 => $this->updated_at,
        ];
    }
}
