<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'status'      => $this->status,
            'sort_order'  => $this->sort_order,
            'articles_count' => $this->articles_count ?? 0,
            'parent_id'   => $this->parent_id,
            'department'  => $this->whenLoaded('department', fn () => [
                'id' => $this->department?->id, 'name' => $this->department?->name,
            ]),
            'children'    => KnowledgeCategoryResource::collection($this->whenLoaded('children')),
            'created_at'  => $this->created_at,
        ];
    }
}
