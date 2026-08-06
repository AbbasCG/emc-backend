<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DynamicFormResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'slug'        => $this->slug,
            'description' => $this->description,
            'type'        => $this->type,
            'status'      => $this->status,
            'department'  => $this->whenLoaded('department', fn () => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
            ]),
            'questions'   => FormQuestionResource::collection($this->whenLoaded('questions')),
            'created_at'  => $this->created_at,
        ];
    }
}
