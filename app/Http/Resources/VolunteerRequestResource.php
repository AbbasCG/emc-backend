<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class VolunteerRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cvViewUrl = $this->signedCvUrl('admin.volunteer-requests.cv.view');

        return [
            'id'                  => $this->id,
            'full_name'           => $this->full_name,
            'email'               => $this->email,
            'phone'               => $this->phone,
            'country'             => $this->country,
            'city'                => $this->city,
            'gender'              => $this->gender,
            'birth_date'          => $this->birth_date?->format('Y-m-d'),
            'nationality'         => $this->nationality,
            'education_level'     => $this->education_level,
            'current_status'      => $this->current_status,
            'job_title'           => $this->job_title,
            'specialization'      => $this->specialization,
            'volunteer_days_availability' => $this->volunteer_days_availability,
            'volunteer_days'      => $this->volunteer_days,
            'preferred_time_slot' => $this->preferred_time_slot,
            'daily_hours'         => $this->daily_hours,
            'in_person_capable'   => $this->in_person_capable,
            'remote_capable'      => $this->remote_capable,
            'has_previous_volunteering'   => $this->has_previous_volunteering,
            'volunteering_organizations'  => $this->volunteering_organizations,
            'volunteering_duration'       => $this->volunteering_duration,
            'past_volunteer_tasks'        => $this->past_volunteer_tasks,
            'personal_skills'     => $this->personal_skills,
            'skill_level_rating'  => $this->skill_level_rating,
            'technical_tools'     => $this->technical_tools,
            'value_add_contributions'     => $this->value_add_contributions,
            'portfolio_link_status'       => $this->portfolio_link_status,
            'portfolio_url'       => $this->portfolio_url,
            'motivation_ratings'  => $this->motivation_ratings,
            'commitment_ratings'  => $this->commitment_ratings,
            'situational_responses'       => $this->situational_responses,
            'expected_duration'   => $this->expected_duration,
            'commitment_three_months'     => $this->commitment_three_months,
            'reasons_for_applying'        => $this->reasons_for_applying,
            'expected_gains'      => $this->expected_gains,
            'secondary_departments'       => $this->secondary_departments,
            'primary_value_department'    => $this->primary_value_department,
            'department_skills'   => $this->department_skills,
            'preferred_work_environment'  => $this->preferred_work_environment,
            'quit_reasons'        => $this->quit_reasons,
            'suggestions'         => $this->suggestions,
            'assessment_data'     => $this->assessment_data,
            'desired_department'  => $this->desired_department,
            'experience_level'    => $this->experience_level,
            'skills'              => $this->skills,
            'availability'        => $this->availability,
            'motivation'          => $this->motivation,
            'previous_experience' => $this->previous_experience,
            'cv_url'              => $cvViewUrl,
            'cv_file_url'         => $cvViewUrl,
            'cv_view_url'         => $cvViewUrl,
            'notes'               => $this->notes,
            // The opportunity this application was made for (null for a general application).
            'opportunity_id'      => $this->opportunity_id,
            'opportunity_title'   => $this->opportunity_id ? ($this->relationLoaded('opportunity') ? $this->opportunity?->title : $this->opportunity()->value('title')) : null,
            'status'              => $this->status,
            'admin_notes'         => $this->admin_notes,
            'reviewed_by'         => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id'   => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),
            'reviewed_at'             => $this->reviewed_at,
            // Acceptance tracking
            'accepted_at'             => $this->accepted_at ?? ($this->status === 'accepted' ? $this->reviewed_at : null),
            'accepted_by'             => $this->whenLoaded('acceptedBy', fn () => $this->acceptedBy ? [
                'id'   => $this->acceptedBy->id,
                'name' => $this->acceptedBy->name,
            ] : null),
            'accepted_by_name'        => $this->whenLoaded('acceptedBy', fn () => $this->acceptedBy?->name),
            // Conversion tracking
            'converted_to_member_at'  => $this->converted_to_member_at,
            'converted_member_id'     => $this->converted_member_id,
            'can_convert_to_member'   => $this->status === 'accepted' && $this->converted_to_member_at === null,
            'is_converted'            => $this->converted_to_member_at !== null,
            'created_at'              => $this->created_at,
            'updated_at'              => $this->updated_at,
        ];
    }

    private function signedCvUrl(string $routeName): ?string
    {
        if (! $this->cvStoragePath()) {
            return null;
        }

        return URL::temporarySignedRoute(
            $routeName,
            now()->addHours(2),
            ['volunteerRequest' => $this->id],
        );
    }
}
