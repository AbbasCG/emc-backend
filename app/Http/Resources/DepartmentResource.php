<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'status'      => $this->status,
            'icon'        => $this->icon,
            'color'       => $this->color,
            'sort_order'  => $this->sort_order,
            'parent_id'   => $this->parent_id,
            'leader'      => $this->whenLoaded('leader', fn () => [
                'id'   => $this->leader->id,
                'name' => $this->leader->name,
            ]),
            'sections'    => DepartmentSectionResource::collection($this->whenLoaded('sections')),
            'children'    => DepartmentResource::collection($this->whenLoaded('children')),
            'created_at'  => $this->created_at,
        ];
    }
}
