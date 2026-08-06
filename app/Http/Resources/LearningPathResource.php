<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearningPathResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'title'             => $this->title,
            'slug'              => $this->slug,
            'short_description' => $this->short_description,
            'full_description'  => $this->full_description,
            'featured_image'    => $this->resolveImageUrl($this->featured_image),
            'duration'          => $this->duration,
            'duration_unit'     => $this->duration_unit ?? 'weeks',
            'language'          => $this->language,
            'level'             => $this->level,
            'certificate_name'  => $this->certificate_name,
            'price'             => $this->price !== null ? (float) $this->price : null,
            'discount_price'    => $this->discount_price !== null ? (float) $this->discount_price : null,
            'status'            => $this->status,
            'is_featured'       => (bool) $this->is_featured,
            'enrollment_open'   => (bool) $this->enrollment_open,
            'learning_outcomes' => $this->ensureArray($this->learning_outcomes),
            'requirements'      => $this->ensureArray($this->requirements),

            'study_days_per_week' => $this->study_days_per_week,
            'study_days'        => $this->ensureArray($this->study_days),
            'study_time'        => $this->study_time,
            'schedule_note'     => $this->schedule_note,

            'courses_count'     => $this->whenLoaded('courses', fn () => $this->courses->count(), $this->courses_count ?? 0),
            'students_count'    => $this->students_count ?? 0,
            'is_path_instructor' => $this->is_path_instructor ?? null,

            'instructor'        => $this->whenLoaded('instructor', fn () => $this->buildInstructor()),
            'instructor_id'     => $this->instructor_id,

            'courses'           => $this->whenLoaded('courses', fn () => $this->courses->map(fn ($c) => [
                'id'                => $c->id,
                'title'             => $c->title,
                'slug'              => $c->slug,
                'short_description' => $c->short_description,
                'image_url'         => $this->resolveImageUrl($c->course_image),
                'duration'          => $c->duration,
                'level'             => $c->level,
                'whatsapp_community_url' => $c->whatsapp_community_url,
                'sort_order'        => $c->pivot->sort_order ?? 0,
            ])->sortBy('sort_order')->values()),

            'created_by'        => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'    => $this->creator->id,
                'name'  => $this->creator->name,
                'email' => $this->creator->email,
                'role'  => $this->creator->role,
            ] : null),
            'updated_by'        => $this->whenLoaded('updater', fn () => $this->updater ? [
                'id'   => $this->updater->id,
                'name' => $this->updater->name,
            ] : null),

            'currency'                 => strtoupper($this->currency ?? 'EUR'),
            'is_paid'                  => $this->isPaid(),
            'is_free'                  => $this->isFree(),
            'finance_approval_status'  => $this->finance_approval_status ?? 'not_required',
            'submitted_for_finance_at' => $this->submitted_for_finance_at,
            'finance_approval'         => $this->whenLoaded('financeApproval', fn () => $this->formatFinanceApproval()),

            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }

    private function formatFinanceApproval(): ?array
    {
        $a = $this->financeApproval;
        if (! $a) {
            return null;
        }
        return [
            'id'               => $a->id,
            'status'           => $a->status,
            'submitted_at'     => $a->submitted_at?->toIso8601String(),
            'reviewed_at'      => $a->reviewed_at?->toIso8601String(),
            'review_note'      => $a->review_note,
            'rejection_reason' => $a->rejection_reason,
            'price_snapshot'   => $a->price_snapshot ? (float) $a->price_snapshot : null,
            'currency_snapshot' => $a->currency_snapshot,
            'submitter'        => $a->relationLoaded('submitter') && $a->submitter ? [
                'id'   => $a->submitter->id,
                'name' => $a->submitter->name,
            ] : null,
            'reviewer'         => $a->relationLoaded('reviewer') && $a->reviewer ? [
                'id'   => $a->reviewer->id,
                'name' => $a->reviewer->name,
            ] : null,
        ];
    }

    private function buildInstructor(): ?array
    {
        if (! $this->instructor) {
            return null;
        }
        $profile = $this->instructor;
        $user    = $profile->relationLoaded('user') ? $profile->user : null;
        $avatar  = $this->resolveImageUrl($user?->avatar ?? $profile->image);

        return [
            'id'         => $profile->id,
            'user_id'    => $profile->user_id,
            'name'       => $user?->name ?? $profile->name,
            'title'      => $profile->title,
            'image'      => $avatar,
            'avatar_url' => $avatar,
        ];
    }

    private function resolveImageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        return asset('storage/' . ltrim($path, '/'));
    }

    private function ensureArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
