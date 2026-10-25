<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $departmentId = null;
        $departmentName = null;
        if ($this->relationLoaded('departments') && $this->departments->count() > 0) {
            $primary = $this->departments->where('pivot.is_primary', true)->first() ?? $this->departments->first();
            $departmentId = $primary->id;
            $departmentName = $primary->name_ar ?? $primary->name;
        } elseif ($this->department) {
            if (is_numeric($this->department)) {
                $departmentId = (int) $this->department;
                $d = \App\Models\Department::find($departmentId);
                $departmentName = $d?->name_ar ?? $d?->name;
            } else {
                $dept = \App\Models\Department::where('slug', $this->department)->orWhere('name_ar', $this->department)->first();
                if ($dept) {
                    $departmentId = $dept->id;
                    $departmentName = $dept->name_ar ?? $dept->name;
                } else {
                    $departmentName = $this->department;
                }
            }
        }

        $hasEnrolledCourses = \App\Models\Registration::where('user_id', $this->id)
            ->whereIn('status', ['confirmed', 'active', 'completed', 'enrolled'])
            ->exists() || \App\Models\ClassGroupStudent::where('user_id', $this->id)->exists();

        $impactPointsBalance = (int) (
            \App\Models\VolunteerPointAward::where('user_id', $this->id)->sum('points') -
            \App\Models\VolunteerPointRedemption::where('user_id', $this->id)->sum('points')
        );

        return [
            'id'                         => $this->id,
            'name'                       => $this->name,
            'email'                      => $this->email,
            'phone'                      => $this->phone,
            'phone_country_code'         => $this->phone_country_code,
            'role'                       => $this->effectiveRole(),
            'department'                 => $this->department,
            'department_id'              => $departmentId,
            'department_name'            => $departmentName,
            'has_enrolled_courses'       => $hasEnrolledCourses,
            'impact_points_balance'      => $impactPointsBalance,
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
