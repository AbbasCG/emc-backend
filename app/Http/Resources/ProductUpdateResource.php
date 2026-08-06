<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductUpdateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id'            => $this->id,
            'title'         => $this->title,
            'body'          => $this->body,
            'category'      => $this->category,
            'update_type'   => $this->update_type ?? 'announcement',
            'status'        => $this->status,
            'target_roles'  => $this->target_roles,
            'notify_in_app' => $this->notify_in_app,
            'notify_email'  => $this->notify_email,
            'published_at'  => $this->published_at?->toIso8601String(),
            'scheduled_at'  => $this->scheduled_at?->toIso8601String(),
            'created_at'    => $this->created_at->toIso8601String(),
            'updated_at'    => $this->updated_at->toIso8601String(),
            'created_by'    => $this->whenLoaded('creator', fn () => [
                'id'   => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'is_read'                  => $user ? $this->reads->where('user_id', $user->id)->isNotEmpty() : false,
            'reads_count'              => $this->reads_count ?? $this->reads->count(),
            // CTA
            'cta_label'                => $this->cta_label,
            'cta_url'                  => $this->cta_url,
            'cta_external'             => (bool) $this->cta_external,
            // Images
            'image_url'                => $this->image_url,
            'image_before_url'         => $this->image_before_url,
            'image_after_url'          => $this->image_after_url,
            // Maintenance
            'maintenance_start'        => $this->maintenance_start?->toIso8601String(),
            'maintenance_end'          => $this->maintenance_end?->toIso8601String(),
            'affected_services'        => $this->affected_services,
            'maintenance_severity'     => $this->maintenance_severity,
            // Action / Security
            'due_date'                 => $this->due_date?->toIso8601String(),
            'assigned_to_roles'        => $this->assigned_to_roles,
            'priority'                 => $this->priority,
            'requires_acknowledgement' => (bool) $this->requires_acknowledgement,
            // Bug fix
            'problem_description'      => $this->problem_description,
            'fix_description'          => $this->fix_description,
            'affected_users'           => $this->affected_users,
            // Misc
            'related_page_url'         => $this->related_page_url,
        ];
    }
}
