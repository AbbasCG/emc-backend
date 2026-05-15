<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'certificate_type'    => $this->certificate_type,
            'title'               => $this->title,
            'professional_title'  => $this->professional_title,
            'verification_code'   => $this->verification_code,
            'qr_code_path'        => $this->qr_code_path,
            'status'              => $this->status,
            'issued_at'           => $this->issued_at,
            'expires_at'          => $this->expires_at,
            'metadata'            => $this->metadata,
            'user'                => $this->whenLoaded('user', fn () => [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ]),
            'course'              => $this->whenLoaded('course', fn () => $this->course
                ? ['id' => $this->course->id, 'title' => $this->course->title]
                : null),
            'track'               => $this->whenLoaded('track', fn () => $this->track
                ? ['id' => $this->track->id, 'name' => $this->track->name]
                : null),
            'workshop'            => $this->whenLoaded('workshop', fn () => $this->workshop
                ? ['id' => $this->workshop->id, 'title' => $this->workshop->title]
                : null),
            'approved_by'         => $this->whenLoaded('approvedBy', fn () => $this->approvedBy
                ? ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name]
                : null),
            'created_at'          => $this->created_at,
        ];
    }
}
