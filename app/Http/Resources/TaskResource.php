<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'parent_task_id'      => $this->parent_task_id,
            'title'               => $this->title,
            'description'         => $this->description,
            'priority'            => $this->priority,
            'task_scope'          => $this->task_scope,
            'points'              => $this->points,
            'estimated_hours'     => $this->estimated_hours,
            'status'              => $this->status,
            'acceptance_status'   => $this->acceptance_status,
            'rejection_reason'    => $this->rejection_reason,
            'accepted_at'         => $this->accepted_at,
            'rejected_at'         => $this->rejected_at,
            'manager_approved_at' => $this->manager_approved_at,
            'manager_approved_by' => $this->manager_approved_by,
            'hr_approved_at'      => $this->hr_approved_at,
            'hr_approved_by'      => $this->hr_approved_by,
            'locked_at'           => $this->locked_at,
            'locked_by'           => $this->locked_by,
            'start_date'          => $this->start_date,
            'due_date'            => $this->due_date,
            'due_datetime'        => $this->due_datetime,
            'notes'               => $this->notes,
            'tags'                => $this->tags,
            'related_type'        => $this->related_type,
            'related_id'          => $this->related_id,
            'department'          => $this->whenLoaded('department', fn () => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
            ]),
            'section'             => $this->whenLoaded('section', fn () => [
                'id'   => $this->section->id,
                'name' => $this->section->name,
            ]),
            'assigned_to'         => $this->whenLoaded('assignedTo', fn () => [
                'id'   => $this->assignedTo->id,
                'name' => $this->assignedTo->name,
            ]),
            'created_by'          => $this->whenLoaded('createdBy', fn () => [
                'id'   => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'evaluation'          => $this->whenLoaded('evaluation', fn () => [
                'id'                 => $this->evaluation->id,
                'evaluator_id'       => $this->evaluation->evaluator_id,
                'delivered_on_time'  => $this->evaluation->delivered_on_time,
                'quality_of_outputs' => $this->evaluation->quality_of_outputs,
                'autonomy'           => $this->evaluation->autonomy,
                'responsiveness'     => $this->evaluation->responsiveness,
                'collaboration'      => $this->evaluation->collaboration,
                'collaboration_na'   => (bool) $this->evaluation->collaboration_na,
                'final_score'        => $this->evaluation->final_score,
                'feedback'           => $this->evaluation->feedback,
                'created_at'         => $this->evaluation->created_at,
            ]),
            'attachments'         => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($att) => [
                'id'        => $att->id,
                'file_name' => $att->file_name,
                'file_path' => asset('storage/' . $att->file_path),
                'file_size' => $att->file_size,
                'mime_type' => $att->mime_type,
            ])),
            'subtasks'            => TaskResource::collection($this->whenLoaded('subtasks')),
            // Cheap counters, present only when the query asked for them (withCount): lists that do not load the
            // relations themselves still tell a group parent apart and show checklist progress.
            'subtasks_count'        => $this->when(array_key_exists('subtasks_count', $this->resource->getAttributes()), fn () => (int) $this->subtasks_count),
            'checklist_items_count' => $this->when(array_key_exists('checklist_items_count', $this->resource->getAttributes()), fn () => (int) $this->checklist_items_count),
            'checklist_done_count'  => $this->when(array_key_exists('checklist_done_count', $this->resource->getAttributes()), fn () => (int) $this->checklist_done_count),
            'comments'            => TaskCommentResource::collection($this->whenLoaded('comments')),
            'checklist_items'     => TaskChecklistItemResource::collection($this->whenLoaded('checklistItems')),
            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,
        ];
    }
}
