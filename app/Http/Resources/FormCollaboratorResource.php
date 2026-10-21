<?php

namespace App\Http\Resources;

use App\Models\FormCollaborator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person a form is shared with. Display facts only: name, role and department names; never an e-mail or phone.
 *
 * @mixin FormCollaborator
 */
class FormCollaboratorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'user'               => $this->whenLoaded('user', fn () => self::employee($this->user)),
            'access_level'       => $this->access_level,
            'can_view_responses' => (bool) $this->can_view_responses,
            'can_correct_responses' => (bool) $this->can_correct_responses,
            'granted_by'         => $this->whenLoaded('grantedBy', fn () => DynamicFormResource::person($this->grantedBy)),
            'granted_at'         => $this->updated_at,
            'created_at'         => $this->created_at,
        ];
    }

    /** @return array{id: int, name: string, role: string, departments: list<string>} */
    public static function employee($user): array
    {
        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'role'        => $user->role,
            'departments' => $user->relationLoaded('departments') ? $user->departments->pluck('name')->values()->all() : [],
        ];
    }
}
