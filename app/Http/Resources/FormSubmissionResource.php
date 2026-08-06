<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'form_id'      => $this->form_id,
            'status'       => $this->status,
            'submitted_at' => $this->submitted_at,
            'metadata'     => $this->metadata,
            'user'         => $this->whenLoaded('user', fn () => [
                'id'    => $this->user?->id,
                'name'  => $this->user?->name,
                'email' => $this->user?->email,
            ]),
            'answers'      => FormAnswerResource::collection($this->whenLoaded('answers')),
            'created_at'   => $this->created_at,
        ];
    }
}
