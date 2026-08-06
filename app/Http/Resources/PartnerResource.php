<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'type'           => $this->type,
            'contact_person' => $this->contact_person,
            'email'          => $this->email,
            'phone'          => $this->phone,
            'website'        => $this->website,
            'country'        => $this->country,
            'city'           => $this->city,
            'status'         => $this->status,
            'notes'          => $this->notes,
            'created_at'     => $this->created_at,
        ];
    }
}
