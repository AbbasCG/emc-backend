<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnershipRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'partner_name' => $this->partner_name,
            'contact_name' => $this->contact_name,
            'email'        => $this->email,
            'phone'        => $this->phone,
            'type'         => $this->type,
            'message'      => $this->message,
            'status'       => $this->status,
            'reviewed_by'  => $this->whenLoaded('reviewedBy', fn () => [
                'id'   => $this->reviewedBy?->id,
                'name' => $this->reviewedBy?->name,
            ]),
            'created_at'   => $this->created_at,
        ];
    }
}
