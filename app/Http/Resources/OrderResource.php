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
            'created_at'                  => $this->created_at?->toDateTimeString(),
            'course' => $this->whenLoaded('course', fn () => [
                'id'    => $this->course?->id,
                'title' => $this->course?->title,
                'slug'  => $this->course?->slug,
            ]),
            'user' => $this->whenLoaded('user', fn () => [
                'id'    => $this->user?->id,
                'name'  => $this->user?->name,
                'email' => $this->user?->email,
            ]),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id'             => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'issued_at'      => $this->invoice->issued_at?->toDateString(),
            ] : null),
        ];
    }
}
