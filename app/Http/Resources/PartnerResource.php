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
            'partnership_type' => $this->partnership_type,
            'project_scope'  => $this->project_scope,
            'classification' => $this->classification,
            'field_of_work'  => $this->field_of_work,
            'contact_person' => $this->contact_person,
            'contact_position' => $this->contact_position,
            'email'          => $this->email,
            'phone'          => $this->phone,
            'website'        => $this->website,
            'country'        => $this->country,
            'city'           => $this->city,
            'assigned_to'    => $this->assigned_to,
            'first_contact_date' => $this->first_contact_date,
            'last_contact_date' => $this->last_contact_date,
            'rejection_reason' => $this->rejection_reason,
            'attachment_url' => $this->attachment_url,
            'status'         => $this->status,
            'onboarding_status' => $this->onboarding_status,
            'activated_at'   => $this->activated_at,
            'notes'          => $this->notes,
            'created_at'     => $this->created_at,
        ];
    }
}
