<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'type'             => $this->type,
            'reportable_type'  => $this->reportable_type,
            'reportable_id'    => $this->reportable_id,
            'summary'          => $this->summary,
            'status'           => $this->status,
            'generated_at'     => $this->generated_at,
            'data'             => $this->when(
                $request->routeIs('*.reports.show') || $request->route('report'),
                $this->data
            ),
            'generated_by'     => $this->whenLoaded('generatedBy', fn () => [
                'id'   => $this->generatedBy?->id,
                'name' => $this->generatedBy?->name,
            ]),
            'created_at'       => $this->created_at,
        ];
    }
}
