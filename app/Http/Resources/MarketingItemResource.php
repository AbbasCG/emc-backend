<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketingItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'description'      => $this->description,
            'platform'         => $this->platform,
            'status'           => $this->status,
            'publish_date'     => $this->publish_date,
            'content_text'     => $this->content_text,
            'assets'           => $this->assets,
            'related_type'     => $this->related_type,
            'related_id'       => $this->related_id,
            'responsible_user' => $this->whenLoaded('responsibleUser', fn () => [
                'id'   => $this->responsibleUser?->id,
                'name' => $this->responsibleUser?->name,
            ]),
            'created_at'       => $this->created_at,
        ];
    }
}
