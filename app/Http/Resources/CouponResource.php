<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'code'                     => $this->code,
            'name'                     => $this->name,
            'description'              => $this->description,
            'discount_type'            => $this->discount_type,
            'discount_value'           => (float) $this->discount_value,
            'maximum_discount_amount'  => $this->maximum_discount_amount !== null ? (float) $this->maximum_discount_amount : null,
            'minimum_order_amount'     => $this->minimum_order_amount !== null ? (float) $this->minimum_order_amount : null,
            'currency'                 => $this->currency,
            'max_uses'                 => $this->max_uses,
            'used_count'               => $this->used_count,
            'remaining_uses'           => $this->max_uses !== null ? max(0, $this->max_uses - $this->used_count) : null,
            'usage_limit_per_user'     => $this->usage_limit_per_user,
            'first_order_only'         => (bool) $this->first_order_only,
            'individual_use_only'      => (bool) $this->individual_use_only,
            'starts_at'                => $this->starts_at,
            'expires_at'               => $this->expires_at,
            'status'                   => $this->status,
            'derived_status'           => $this->derivedStatus(),
            'eligibility_type'         => $this->eligibility_type,
            'courses_count'            => $this->when(isset($this->courses_count), fn () => $this->courses_count),
            'courses'                  => $this->whenLoaded('courses', fn () => $this->courses->map(fn ($c) => [
                'id' => $c->id, 'title' => $c->title, 'price' => $c->price !== null ? (float) $c->price : null, 'status' => $c->status,
            ])),
            'excluded_courses'         => $this->whenLoaded('excludedCourses', fn () => $this->excludedCourses->map(fn ($c) => ['id' => $c->id, 'title' => $c->title])),
            'is_valid'                 => $this->isValid(),
            'created_by'               => $this->whenLoaded('creator', fn () => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null),
            'updated_by'               => $this->whenLoaded('updater', fn () => $this->updater ? ['id' => $this->updater->id, 'name' => $this->updater->name] : null),
            'created_at'               => $this->created_at,
        ];
    }
}
