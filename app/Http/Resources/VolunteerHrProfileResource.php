<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class VolunteerHrProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'      => $this->id,
            'user_id' => $this->user_id,

            // ── Personal information ──
            'full_name'          => $this->full_name,
            'email'              => $this->email,
            'phone'              => $this->phone,
            'phone_country_code' => $this->phone_country_code,
            'country'            => $this->country,
            'country_code'       => $this->country_code,
            'city'               => $this->city,
            'nationality'        => $this->nationality,
            'gender'             => $this->gender,
            'date_of_birth'      => $this->date_of_birth?->format('Y-m-d'),
            'profile_photo_url'  => $this->profile_photo_path ? Storage::disk('public')->url($this->profile_photo_path) : null,

            // ── Volunteer information ──
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name_ar ?? $this->department->name,
            ] : null),
            'department_id'   => $this->department_id,
            'job_title'       => $this->job_title,
            'employment_type' => $this->employment_type,
            'join_date'       => $this->join_date?->format('Y-m-d'),
            'availability'    => $this->availability,
            'weekly_hours'    => $this->weekly_hours,

            // ── Skills and experience ──
            'skills'        => $this->skills,
            'languages'     => $this->languages,
            'education'     => $this->education,
            'experience'    => $this->experience,
            'motivation'    => $this->motivation,
            'linkedin_url'  => $this->linkedin_url,
            'portfolio_url' => $this->portfolio_url,

            // ── Documents — metadata only, never the raw storage path. The
            // frontend builds preview/download URLs from `id` against the
            // role-gated /hr/volunteers/{id}/cv/{preview,download} endpoints
            // (same convention as AdminAmbassadorApplicationFileController). ──
            'cv' => [
                'available'      => (bool) $this->cv_path,
                // Never fall back to the raw stored filename (it's a random
                // token, not user-facing) — null here just means "uploaded
                // before original-filename tracking existed."
                'file_name'      => $this->cv_original_name,
                'mime_type'      => $this->cv_mime_type,
                'size'           => $this->cv_size,
                'uploaded_at'    => $this->cv_path ? $this->updated_at?->toIso8601String() : null,
            ],

            // ── Workflow data ──
            'status'           => $this->status,
            'submitted_at'     => $this->submitted_at?->toIso8601String(),
            'reviewed_at'      => $this->reviewed_at?->toIso8601String(),
            'reviewed_by'      => $this->whenLoaded('reviewer', fn () => $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null),
            'rejection_reason' => $this->rejection_reason,
            'approved_at'      => $this->approved_at?->toIso8601String(),
            'approved_by'      => $this->whenLoaded('approver', fn () => $this->approver ? ['id' => $this->approver->id, 'name' => $this->approver->name] : null),
            'team_profile_id'  => $this->team_profile_id,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
