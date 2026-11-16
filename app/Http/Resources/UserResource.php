<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /** Set by forSelf(): the payload describes the caller's own account (login / register, where no user is authenticated yet). */
    private bool $selfScope = false;

    public static function forSelf(mixed $user): static
    {
        $resource = new static($user);
        $resource->selfScope = true;

        return $resource;
    }

    public function toArray(Request $request): array
    {
        $departmentId = null;
        $departmentName = null;
        $departmentSlug = null;
        if ($this->relationLoaded('departments') && $this->departments->count() > 0) {
            $primary = $this->departments->where('pivot.is_primary', true)->first() ?? $this->departments->first();
            $departmentId = $primary->id;
            $departmentName = $primary->name_ar ?? $primary->name;
            $departmentSlug = $primary->slug;
        } elseif ($this->department) {
            if (is_numeric($this->department)) {
                $departmentId = (int) $this->department;
                $d = \App\Models\Department::find($departmentId);
                $departmentName = $d?->name_ar ?? $d?->name;
                $departmentSlug = $d?->slug;
            } else {
                $dept = \App\Models\Department::where('slug', $this->department)
                    ->orWhere('name_ar', $this->department)
                    ->orWhere('name', $this->department)
                    ->first();
                if ($dept) {
                    $departmentId = $dept->id;
                    $departmentName = $dept->name_ar ?? $dept->name;
                    $departmentSlug = $dept->slug;
                } else {
                    $departmentName = $this->department;
                }
            }
        }
        if (! $departmentSlug && $this->id) {
            $ledDept = \App\Models\Department::where('leader_id', $this->id)->first();
            if ($ledDept) {
                $departmentId = $departmentId ?? $ledDept->id;
                $departmentName = $departmentName ?? ($ledDept->name_ar ?? $ledDept->name);
                $departmentSlug = $ledDept->slug;
            }
        }

        $specializedRole = method_exists($this->resource, 'getDepartmentSpecializedRole')
            ? $this->resource->getDepartmentSpecializedRole()
            : null;

        // Learning / impact-points summaries cost several queries and belong to the account holder alone. This resource is also
        // nested in lists (submissions, attendance), where they would be an N+1 and would show other people's balances.
        $isSelf = $this->selfScope || ($request->user() !== null && $request->user()->is($this->resource));

        return [
            'id'                         => $this->id,
            'name'                       => $this->name,
            'email'                      => $this->email,
            'phone'                      => $this->phone,
            'phone_country_code'         => $this->phone_country_code,
            'role'                       => $this->role,
            'effective_role'             => $this->effectiveRole(),
            'specialized_role'           => $specializedRole,
            'department'                 => $this->department,
            'department_id'              => $departmentId,
            'department_slug'            => $departmentSlug,
            'department_name'            => $departmentName,
            'has_enrolled_courses'       => $this->when($isSelf, fn () => \App\Models\Registration::where('user_id', $this->id)
                ->whereIn('status', ['confirmed', 'active', 'completed', 'enrolled'])
                ->exists() || \App\Models\ClassGroupStudent::where('user_id', $this->id)->exists()),
            'impact_points_balance'      => $this->when($isSelf, fn () => (int) (
                \App\Models\VolunteerPointAward::where('user_id', $this->id)->sum('points') -
                \App\Models\VolunteerPointRedemption::where('user_id', $this->id)->sum('points')
            )),
            'is_active'                  => $this->is_active ?? true,
            // True while the account still holds a temporary password: the SPA must send the user to the change-password screen.
            'must_change_password'       => $this->when($isSelf, fn () => (bool) $this->must_change_password),
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
