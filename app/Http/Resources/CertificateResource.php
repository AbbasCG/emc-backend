<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isStudent = $request->is('api/student/*');

        return [
            // ── Identity ──────────────────────────────────────────────────────
            'id'                   => $this->id,
            'certificate_type'     => $this->certificate_type,
            'certificate_code'     => $this->certificate_code,
            'title'                => $this->title,
            'professional_title'   => $this->professional_title,
            'verification_code'    => $this->verification_code,
            'qr_code_path'         => $this->qr_code_path,

            // ── Status ────────────────────────────────────────────────────────
            'status'               => $this->status,
            'approval_status'      => $this->approval_status,

            // ── Dates ─────────────────────────────────────────────────────────
            'issued_at'            => $this->issued_at?->toIso8601String(),
            'expires_at'           => $this->expires_at?->toIso8601String(),
            'revoked_at'           => $this->revoked_at?->toIso8601String(),
            'created_at'           => $this->created_at?->toIso8601String(),
            'updated_at'           => $this->updated_at?->toIso8601String(),

            // ── Revocation ───────────────────────────────────────────────────
            'revoke_reason'        => $this->revoke_reason,

            // ── Recipient snapshot ───────────────────────────────────────────
            'recipient_name'       => $this->recipient_name,
            'recipient_email'      => $this->recipient_email,

            // ── Batch ────────────────────────────────────────────────────────
            'batch_id'             => $this->batch_id,
            'metadata'             => $this->metadata,

            // ── PDF info ─────────────────────────────────────────────────────
            'pdf_url'              => $this->pdf_path
                ? ($isStudent
                    ? url('/api/student/certificates/' . $this->id . '/download')
                    : url('/api/admin/certificates/' . $this->id . '/download'))
                : null,
            'pdf_info'             => $isStudent ? null : [
                'has_pdf'             => (bool) $this->pdf_path,
                'generation_status'   => $this->pdf_generation_status ?? ($this->pdf_path ? 'ready' : 'none'),
                'generated_at'        => $this->pdf_generated_at?->toIso8601String(),
                'file_size_bytes'     => $this->pdf_file_size,
                'template_id_used'    => $this->template_id_used,
                'last_error'          => $this->pdf_last_error,
            ],

            // ── Relations (loaded on demand) ─────────────────────────────────
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ] : null),

            'course' => $this->whenLoaded('course', fn () => $this->course
                ? ['id' => $this->course->id, 'title' => $this->course->title]
                : null),

            'track' => $this->whenLoaded('track', fn () => $this->track
                ? ['id' => $this->track->id, 'name' => $this->track->name]
                : null),

            'workshop' => $this->whenLoaded('workshop', fn () => $this->workshop
                ? ['id' => $this->workshop->id, 'title' => $this->workshop->title]
                : null),

            'learning_path' => $this->whenLoaded('learningPath', fn () => $this->learningPath
                ? ['id' => $this->learningPath->id, 'name' => $this->learningPath->name ?? $this->learningPath->title]
                : null),

            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy
                ? ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name]
                : null),

            'issued_by' => $this->whenLoaded('issuedBy', fn () => $this->issuedBy
                ? ['id' => $this->issuedBy->id, 'name' => $this->issuedBy->name]
                : null),

            'template' => $this->whenLoaded('template', fn () => $this->template ? [
                'id'          => $this->template->id,
                'name'        => $this->template->name,
                'type'        => $this->template->type,
                'language'    => $this->template->language,
                'code_prefix' => $this->template->code_prefix,
            ] : null),

            'batch' => $this->whenLoaded('batch', fn () => $this->batch ? [
                'id'         => $this->batch->id,
                'batch_code' => $this->batch->batch_code,
                'status'     => $this->batch->status,
            ] : null),

            'approvals' => $this->whenLoaded('approvals', fn () =>
                $this->approvals->map(fn ($a) => [
                    'type'        => $a->type,
                    'type_label'  => $a->typeLabel(),
                    'status'      => $a->status,
                    'approver'    => $a->approvedBy ? ['id' => $a->approvedBy->id, 'name' => $a->approvedBy->name] : null,
                    'notes'       => $a->notes,
                    'actioned_at' => $a->actioned_at?->toIso8601String(),
                ])->values()
            ),
        ];
    }
}
