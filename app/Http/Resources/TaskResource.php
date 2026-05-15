<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'description'  => $this->description,
            'priority'     => $this->priority,
            'status'       => $this->status,
            'start_date'   => $this->start_date,
            'due_date'     => $this->due_date,
            'tags'         => $this->tags,
            'related_type' => $this->related_type,
            'related_id'   => $this->related_id,
            'department'   => $this->whenLoaded('department', fn () => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
            ]),
            'section'      => $this->whenLoaded('section', fn () => [
                'id'   => $this->section->id,
                'name' => $this->section->name,
            ]),
            'assigned_to'  => $this->whenLoaded('assignedTo', fn () => [
                'id'   => $this->assignedTo->id,
                'name' => $this->assignedTo->name,
            ]),
            'created_by'   => $this->whenLoaded('createdBy', fn () => [
                'id'   => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'comments'         => TaskCommentResource::collection($this->whenLoaded('comments')),
            'checklist_items'  => TaskChecklistItemResource::collection($this->whenLoaded('checklistItems')),
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}
