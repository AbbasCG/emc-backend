<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancialTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'type'            => $this->type,
            'amount'          => $this->amount,
            'currency'        => $this->currency,
            'status'          => $this->status,
            'description'     => $this->description,
            'occurred_at'     => $this->occurred_at,
            'payment_id'      => $this->payment_id,
            'registration_id' => $this->registration_id,
            'user'            => $this->whenLoaded('user', fn () => [
                'id'    => $this->user?->id,
                'name'  => $this->user?->name,
                'email' => $this->user?->email,
            ]),
            'created_at'      => $this->created_at,
        ];
    }
}
