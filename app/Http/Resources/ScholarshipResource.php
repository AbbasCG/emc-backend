<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScholarshipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'name'                => $this->name,
            'email'               => $this->email,
            'type'                => $this->type,
            'discount_percentage' => $this->discount_percentage,
            'amount'              => $this->amount,
            'reason'              => $this->reason,
            'status'              => $this->status,
            'approved_at'         => $this->approved_at,
            'user'                => $this->whenLoaded('user', fn () => [
                'id'    => $this->user?->id,
                'name'  => $this->user?->name,
                'email' => $this->user?->email,
            ]),
            'approved_by'         => $this->whenLoaded('approvedBy', fn () => [
                'id'   => $this->approvedBy?->id,
                'name' => $this->approvedBy?->name,
            ]),
            'created_at'          => $this->created_at,
        ];
    }
}
