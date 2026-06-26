<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'certificate_type'     => $this->certificate_type,
            'certificate_code'     => $this->certificate_code,
            'title'                => $this->title,
            'professional_title'   => $this->professional_title,
            'verification_code'    => $this->verification_code,
            'qr_code_path'         => $this->qr_code_path,
            'pdf_url'              => $this->pdf_path
                ? (request()->is('api/student/*')
                    ? url('/api/student/certificates/' . $this->id . '/download')
                    : url('/api/admin/certificates/' . $this->id . '/download'))
                : null,
            'status'               => $this->status,
            'issued_at'            => $this->issued_at,
            'expires_at'           => $this->expires_at,
            'revoked_at'           => $this->revoked_at,
            'revoke_reason'        => $this->revoke_reason,
            'recipient_name'       => $this->recipient_name,
            'recipient_email'      => $this->recipient_email,
            'batch_id'             => $this->batch_id,
            'metadata'             => $this->metadata,
            'created_at'           => $this->created_at,

            'user'                 => $this->whenLoaded('user', fn () => $this->user ? [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ] : null),

            'course'               => $this->whenLoaded('course', fn () => $this->course
                ? ['id' => $this->course->id, 'title' => $this->course->title]
                : null),

            'track'                => $this->whenLoaded('track', fn () => $this->track
                ? ['id' => $this->track->id, 'name' => $this->track->name]
                : null),

            'workshop'             => $this->whenLoaded('workshop', fn () => $this->workshop
                ? ['id' => $this->workshop->id, 'title' => $this->workshop->title]
                : null),

            'approved_by'          => $this->whenLoaded('approvedBy', fn () => $this->approvedBy
                ? ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name]
                : null),

            'issued_by'            => $this->whenLoaded('issuedBy', fn () => $this->issuedBy
                ? ['id' => $this->issuedBy->id, 'name' => $this->issuedBy->name]
                : null),

            'template'             => $this->whenLoaded('template', fn () => $this->template ? [
                'id'          => $this->template->id,
                'name'        => $this->template->name,
                'type'        => $this->template->type,
                'language'    => $this->template->language,
                'code_prefix' => $this->template->code_prefix,
            ] : null),

            'batch'                => $this->whenLoaded('batch', fn () => $this->batch ? [
                'id'         => $this->batch->id,
                'batch_code' => $this->batch->batch_code,
                'status'     => $this->batch->status,
            ] : null),
        ];
    }
}
