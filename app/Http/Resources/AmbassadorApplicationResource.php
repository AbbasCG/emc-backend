<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AmbassadorApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->role
            && in_array($request->user()->role, ['super_admin', 'admin', 'hr_manager'], true);

        return [
            'id'         => $this->id,
            'uuid'       => $this->uuid,
            'status'     => $this->status,
            'is_draft'   => (bool) $this->is_draft,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Personal
            'full_name'     => $this->full_name,
            'email'         => $this->email,
            'mobile_phone'  => $this->mobile_phone,
            'nationality'   => $this->nationality,
            'gender'        => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'country'       => $this->country,
            'city'          => $this->city,

            // University
            'university_name'          => $this->university_name,
            'university_type'          => $this->university_type,
            'faculty'                  => $this->faculty,
            'major'                    => $this->major,
            'study_year'               => $this->study_year,
            'student_number'           => $this->student_number,
            'student_status'           => $this->student_status,
            'expected_graduation_date' => $this->expected_graduation_date?->toDateString(),

            // Ambassador info
            'motivation_why'                    => $this->motivation_why,
            'contribution_what'                 => $this->contribution_what,
            'expected_gain'                     => $this->expected_gain,
            'biggest_achievement'               => $this->biggest_achievement,
            'biggest_challenge'                 => $this->biggest_challenge,
            'has_volunteer_experience'          => (bool) $this->has_volunteer_experience,
            'has_event_organization_experience' => (bool) $this->has_event_organization_experience,
            'has_teaching_experience'           => (bool) $this->has_teaching_experience,
            'has_student_club_involvement'      => (bool) $this->has_student_club_involvement,
            'social_instagram'  => $this->social_instagram,
            'social_linkedin'   => $this->social_linkedin,
            'social_facebook'   => $this->social_facebook,
            'social_tiktok'     => $this->social_tiktok,
            'social_github'     => $this->social_github,
            'website_url'       => $this->website_url,
            'followers_count'   => $this->followers_count,

            // Arrays
            'interests'                  => $this->interests ?? [],
            'skills'                     => $this->skills ?? [],
            'volunteer_experience_types' => $this->volunteer_experience_types ?? [],
            'certifications'             => $this->certifications ?? [],

            // Leadership
            'has_led_team'               => (bool) $this->has_led_team,
            'has_organized_events'       => (bool) $this->has_organized_events,
            'events_attendees_count'     => $this->events_attendees_count,
            'has_represented_university' => (bool) $this->has_represented_university,

            // Availability
            'weekly_hours_available'       => $this->weekly_hours_available,
            'can_attend_university_events' => (bool) $this->can_attend_university_events,
            'can_represent_emc_outside'    => (bool) $this->can_represent_emc_outside,
            'can_travel'                   => (bool) $this->can_travel,
            'owns_laptop'                  => (bool) $this->owns_laptop,
            'has_stable_internet'          => (bool) $this->has_stable_internet,
            'can_attend_weekly_meetings'   => (bool) $this->can_attend_weekly_meetings,

            // File URLs (signed, admin-only)
            'cv_download_url'       => $isAdmin && $this->cv_path
                ? route('admin.ambassador.cv.download', $this->id) : null,
            'cv_view_url'           => $isAdmin && $this->cv_path
                ? route('admin.ambassador.cv.view', $this->id) : null,
            'has_student_id'        => (bool) $this->student_id_path,
            'student_id_download_url' => $isAdmin && $this->student_id_path
                ? route('admin.ambassador.student-id.download', $this->id) : null,
            'has_photo'             => (bool) $this->photo_path,
            'photo_url'             => $isAdmin && $this->photo_path
                ? route('admin.ambassador.photo.view', $this->id) : null,
            'certificate_count'     => count($this->certificate_paths ?? []),

            // Admin-only fields
            'admin_notes'            => $this->when($isAdmin, $this->admin_notes),
            'interview_scheduled_at' => $this->when($isAdmin, $this->interview_scheduled_at?->toIso8601String()),
            'reviewed_by'            => $this->when($isAdmin, $this->whenLoaded('reviewer', fn () => [
                'id'   => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ])),
            'reviewed_at'          => $this->when($isAdmin, $this->reviewed_at?->toIso8601String()),
            'status_updated_by'    => $this->when($isAdmin, $this->whenLoaded('statusUpdatedBy', fn () => [
                'id'   => $this->statusUpdatedBy->id,
                'name' => $this->statusUpdatedBy->name,
            ])),
            'status_updated_at'    => $this->when($isAdmin, $this->status_updated_at?->toIso8601String()),
            'notes'                => $this->when($isAdmin, $this->whenLoaded('notes', fn () =>
                $this->notes->map(fn ($n) => [
                    'id'         => $n->id,
                    'content'    => $n->content,
                    'is_private' => $n->is_private,
                    'author'     => $n->author?->name ?? 'مجهول',
                    'created_at' => $n->created_at?->toIso8601String(),
                ])
            )),
            'status_history' => $this->when($isAdmin, $this->whenLoaded('statusHistory', fn () =>
                $this->statusHistory->map(fn ($h) => [
                    'id'          => $h->id,
                    'from_status' => $h->from_status,
                    'to_status'   => $h->to_status,
                    'reason'      => $h->reason,
                    'changed_by'  => $h->changedBy?->name ?? 'النظام',
                    'changed_at'  => $h->changed_at?->toIso8601String(),
                ])
            )),
        ];
    }
}
