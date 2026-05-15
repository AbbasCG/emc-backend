<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class WorkshopRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                         => $this->id,
            'requester_name'             => $this->requester_name,
            'requester_email'            => $this->requester_email,
            'requester_phone'            => $this->requester_phone,
            'requester_department'       => $this->requester_department,
            'program_name'               => $this->program_name,
            'speaker_name'               => $this->speaker_name,
            'speaker_job_title'          => $this->speaker_job_title,
            // Return a full public URL, not the raw storage path
            'speaker_photo_url'          => $this->speaker_photo
                ? Storage::disk('public')->url($this->speaker_photo)
                : null,
            'topics'                     => $this->topics,
            'categories'                 => $this->categories,
            'target_audience'            => $this->target_audience,
            'proposed_date'              => $this->proposed_date,
            'proposed_time'              => $this->proposed_time,
            'location_types'             => $this->location_types,
            'price_type'                 => $this->price_type,
            'price_amount'               => $this->price_amount,
            'generated_announcement_text' => $this->generated_announcement_text,
            'status'                     => $this->status,
            'created_at'                 => $this->created_at,
        ];
    }
}
