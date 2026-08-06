<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'department_id' => $this->department_id,
            'name'          => $this->name,
            'description'   => $this->description,
            'status'        => $this->status,
            'sort_order'    => $this->sort_order,
            'leader'        => $this->whenLoaded('leader', fn () => [
                'id'   => $this->leader->id,
                'name' => $this->leader->name,
            ]),
            'created_at'    => $this->created_at,
        ];
    }
}
