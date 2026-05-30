<?php

namespace App\Http\Resources;

use App\Support\PaymentStatuses;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'registration_id' => $this->registration_id,
            'amount'          => $this->amount,
            'provider'        => $this->provider,
            'status'          => $this->status,
            // checkout_url is only useful while payment is pending
            'checkout_url'    => $this->status === PaymentStatuses::PENDING ? $this->checkout_url : null,
            'receipt_url'     => $this->receipt_url,
            'confirmed_at'    => $this->confirmed_at,
            'created_at'      => $this->created_at,
            'registration'    => $this->when(
                $this->relationLoaded('registration'),
                fn () => [
                    'id'         => $this->registration->id,
                    'full_name'  => $this->registration->full_name,
                    'email'      => $this->registration->email,
                    'status'     => $this->registration->status,
                    'course_id'  => $this->registration->course_id,
                ]
            ),
            // provider_payment_id intentionally excluded (internal gateway reference)
        ];
    }
}
