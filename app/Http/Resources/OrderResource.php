<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                          => $this->id,
            'order_number'                => $this->order_number,
            'type'                        => $this->type,
            'subtotal'                    => $this->subtotal,
            'tax_amount'                  => $this->tax_amount,
            'total'                       => $this->total,
            'currency'                    => strtoupper($this->currency ?? 'EUR'),
            'status'                      => $this->status,
            'payment_provider'            => $this->payment_provider,
            'provider_payment_intent_id'  => $this->provider_payment_intent_id,
            'paid_at'                     => $this->paid_at?->toDateTimeString(),
            // Only where the endpoint computed it (the learner's own orders): paid, but the enrollment awaits a financial review.
            'payment_under_review'        => $this->when(array_key_exists('payment_under_review', $this->resource->getAttributes()), fn () => (bool) $this->payment_under_review),
            'created_at'                  => $this->created_at?->toDateTimeString(),
            'course' => $this->whenLoaded('course', fn () => [
                'id'    => $this->course?->id,
                'title' => $this->course?->title,
                'slug'  => $this->course?->slug,
            ]),
            'user' => $this->whenLoaded('user', fn () => [
                'id'                 => $this->user?->id,
                'name'               => $this->user?->name,
                'email'              => $this->user?->email,
                'phone'              => $this->user?->phone,
                'phone_country_code' => $this->user?->phone_country_code,
                'city'               => $this->user?->city,
                'country'            => $this->user?->country,
                'avatar_url'         => $this->user?->avatar_url,
            ]),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id'             => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'issued_at'      => $this->invoice->issued_at?->toDateString(),
            ] : null),
        ];
    }
}
