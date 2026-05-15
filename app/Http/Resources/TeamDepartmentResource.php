<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamDepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name_ar'        => $this->name_ar ?: $this->name,   // fallback to legacy name
            'name_en'        => $this->name_en,
            'slug'           => $this->slug,
            'description_ar' => $this->description_ar ?: $this->description,
            'icon'           => $this->icon,
            'sort_order'     => $this->sort_order,
            'members'        => TeamMemberResource::collection(
                $this->whenLoaded('activeTeamProfiles')
            ),
        ];
    }
}
