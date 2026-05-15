<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AutomationRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'trigger_type' => $this->trigger_type,
            'conditions'   => $this->conditions,
            'actions'      => $this->actions,
            'status'       => $this->status,
            'created_by'   => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy?->id, 'name' => $this->createdBy?->name,
            ]),
            'runs_count'   => $this->whenCounted('runs'),
            'created_at'   => $this->created_at,
        ];
    }
}
