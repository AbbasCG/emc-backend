<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class VolunteerRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cvDownloadUrl = $this->signedCvUrl('admin.volunteer-requests.cv');
        $cvViewUrl     = $this->signedCvUrl('admin.volunteer-requests.cv.view');

        return [
            'id'                  => $this->id,
            'full_name'           => $this->full_name,
            'email'               => $this->email,
            'phone'               => $this->phone,
            'country'             => $this->country,
            'city'                => $this->city,
            'gender'              => $this->gender,
            'desired_department'  => $this->desired_department,
            'experience_level'    => $this->experience_level,
            'skills'              => $this->skills,
            'availability'        => $this->availability,
            'motivation'          => $this->motivation,
            'previous_experience' => $this->previous_experience,
            'cv_url'              => $cvDownloadUrl,
            'cv_file_url'         => $cvDownloadUrl,
            'cv_download_url'     => $cvDownloadUrl,
            'cv_view_url'         => $cvViewUrl,
            'notes'               => $this->notes,
            'status'              => $this->status,
            'admin_notes'         => $this->admin_notes,
            'reviewed_by'         => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id'   => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),
            'reviewed_at'             => $this->reviewed_at,
            'converted_to_member_at'  => $this->converted_to_member_at,
            'converted_member_id'     => $this->converted_member_id,
            'can_convert_to_member'   => $this->status === 'accepted' && $this->converted_to_member_at === null,
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
