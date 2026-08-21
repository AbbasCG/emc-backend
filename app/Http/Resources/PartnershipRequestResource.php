<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnershipRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'partner_name'           => $this->partner_name,
            'contact_name'           => $this->contact_name,
            'email'                  => $this->email,
            'phone'                  => $this->phone,
            'country'                => $this->country,
            'city'                   => $this->city,
            'website'                => $this->website,
            'type'                   => $this->type,
            'type_other'             => $this->type_other,
            'partnership_type'       => $this->partnership_type,
            'partnership_type_other' => $this->partnership_type_other,
            'message'                => $this->message,
            'privacy_accepted'       => (bool) $this->privacy_accepted,
            'status'                 => $this->status,
            'review_notes'           => $this->review_notes,
            'reviewed_at'            => $this->reviewed_at,
            'reviewed_by'  => $this->whenLoaded('reviewedBy', fn () => [
                'id'   => $this->reviewedBy?->id,
                'name' => $this->reviewedBy?->name,
            ]),
            'converted_partner' => $this->whenLoaded('convertedPartner', fn () => $this->convertedPartner ? [
                'id' => $this->convertedPartner->id,
                'name' => $this->convertedPartner->name,
                'status' => $this->convertedPartner->status,
            ] : null),
            'converted_user' => $this->whenLoaded('convertedUser', fn () => $this->convertedUser ? [
                'id' => $this->convertedUser->id,
                'name' => $this->convertedUser->name,
                'email' => $this->convertedUser->email,
            ] : null),
            'created_at'   => $this->created_at,
        ];
    }
}
