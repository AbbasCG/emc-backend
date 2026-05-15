<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'code'             => $this->code,
            'name'             => $this->name,
            'description'      => $this->description,
            'discount_type'    => $this->discount_type,
            'discount_value'   => $this->discount_value,
            'max_uses'         => $this->max_uses,
            'used_count'       => $this->used_count,
            'starts_at'        => $this->starts_at,
            'expires_at'       => $this->expires_at,
            'status'           => $this->status,
            'applies_to_type'  => $this->applies_to_type,
            'applies_to_id'    => $this->applies_to_id,
            'is_valid'         => $this->isValid(),
            'created_at'       => $this->created_at,
        ];
    }
}
