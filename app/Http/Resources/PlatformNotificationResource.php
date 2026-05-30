<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'type'        => $this->type,
            'title'       => $this->title,
            'message'     => $this->message,
            'icon'        => $this->icon,
            'color'       => $this->color,
            'is_read'     => (bool) $this->is_read,
            'read_at'     => $this->read_at,
            'action_url'  => $this->action_url,
            'entity_type' => $this->entity_type,
            'entity_id'   => $this->entity_id,
            'created_at'  => $this->created_at,
            'time_ago'    => $this->created_at?->diffForHumans(),
        ];
    }
}
