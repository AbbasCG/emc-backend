<?php

namespace App\Http\Resources;

use App\Models\ActivityLog;
use App\Services\Forms\FormActivityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of a form's history: who, when, what, and a short summary of the change.
 *
 * @mixin ActivityLog
 */
class FormActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $action = preg_replace('/^form\./', '', (string) $this->action);

        return [
            'id'         => $this->id,
            'action'     => $action,
            'label'      => FormActivityService::LABELS[$action] ?? $action,
            'actor'      => $this->whenLoaded('user', fn () => DynamicFormResource::person($this->user)),
            'details'    => $this->new_values ?? (object) [],
            'created_at' => $this->created_at,
        ];
    }
}
