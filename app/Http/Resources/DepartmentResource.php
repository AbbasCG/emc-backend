<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'name_ar'        => $this->name_ar ?? $this->name,
            'name_en'        => $this->name_en,
            'slug'           => $this->slug,
            'description'    => $this->description,
            'description_ar' => $this->description_ar ?? $this->description,
            'status'         => $this->status ?? 'active',
            'is_active'      => (bool) ($this->is_active ?? true),
            'icon'           => $this->icon,
            'color'          => $this->color,
            'sort_order'     => $this->sort_order,
            'parent_id'      => $this->parent_id,

            'leader'   => $this->whenLoaded('leader', fn () => $this->leader ? [
                'id'    => $this->leader->id,
                'name'  => $this->leader->name,
                'email' => $this->leader->email,
                'role'  => $this->leader->role,
            ] : null),
            'sections' => DepartmentSectionResource::collection($this->whenLoaded('sections')),
            'children' => DepartmentResource::collection($this->whenLoaded('children')),

            // ── Real metrics ──────────────────────────────────────────────────
            // Source: team_profiles.department_id (public org-chart profiles)
            'members_count'            => (int) ($this->members_count ?? 0),
            'leaders_count'            => (int) ($this->leaders_count ?? 0),
            // Source: courses.department_id
            'courses_count'            => (int) ($this->courses_count ?? 0),
            // Source: tasks.department_id, status not in (completed, cancelled)
            'open_tasks_count'         => (int) ($this->open_tasks_count ?? 0),
            // Source: volunteer_requests.desired_department = name_ar
            'volunteer_requests_count' => (int) ($this->volunteer_requests_count ?? 0),
            // Combined "needs attention": pending volunteer requests + open tasks
            'pending_items_count'      => (int) ($this->pending_volunteer_count ?? 0)
                                        + (int) ($this->open_tasks_count ?? 0),

            'created_at' => $this->created_at,
        ];
    }
}
