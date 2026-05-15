<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name_ar'      => $this->name_ar,
            'name_en'      => $this->name_en,
            'position_ar'  => $this->position_ar,
            'position_en'  => $this->position_en,
            'role_key'     => $this->role_key,
            'country'      => $this->country,
            'image'        => $this->image
                ? (str_starts_with($this->image, 'http') ? $this->image : asset('storage/' . $this->image))
                : null,
            'bio_ar'       => $this->bio_ar,
            'sort_order'   => $this->sort_order,
            'is_leader'    => (bool) $this->is_leader,
            'is_executive' => (bool) $this->is_executive,
        ];
    }
}
