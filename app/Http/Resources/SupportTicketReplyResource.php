<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketReplyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'body'             => $this->message,
            'message'          => $this->message,
            'user_id'          => $this->user_id,
            'author_id'        => $this->user_id,
            'author_name'      => $this->whenLoaded('user', fn () => $this->user?->name ?? 'فريق الدعم', 'فريق الدعم'),
            'is_internal_note' => $this->is_internal_note,
            'internal'         => $this->is_internal_note,
            'user'             => $this->whenLoaded('user', fn () => [
                'id'     => $this->user?->id,
                'name'   => $this->user?->name,
                'avatar' => $this->user?->avatar ?? $this->user?->avatar_url ?? null,
            ]),
            'created_at'       => optional($this->created_at)->toISOString(),
        ];
    }
}
