<?php

namespace App\Http\Resources;

use App\Models\DynamicForm;
use App\Models\User;
use App\Services\Forms\FormAccessService;
use App\Services\Forms\FormSnapshotService;
use App\Services\Forms\FormAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A form as the staff screens see it.
 *
 * `form_type` repeats `type` under the name the form builder uses. People appear only as {id, name} — no e-mail or other
 * contact detail. The response count and last response date are included only for someone who may read the responses, and
 * `abilities` tells the screen what to offer (the server re-checks every action regardless).
 *
 * @mixin DynamicForm
 */
class DynamicFormResource extends JsonResource
{
    private ?bool $hasUnpublishedChanges = null;

    /** Adds `has_unpublished_changes` (computed by the caller: it needs the draft's questions). */
    public function withDraftState(bool $hasUnpublishedChanges): static
    {
        $this->hasUnpublishedChanges = $hasUnpublishedChanges;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $user = $request->user();
        $abilities = $user instanceof User ? app(FormAccessService::class)->abilities($user, $this->resource) : [];
        $canSeeResponses = (bool) ($abilities['view_responses'] ?? false);
        $version = $this->relationLoaded('publishedVersion') ? $this->publishedVersion : null;

        return [
            'id'                   => $this->id,
            'title'                => $this->title,
            'slug'                 => $this->slug,
            'description'          => $this->description,
            'type'                 => $this->type,
            'form_type'            => $this->type,
            // How a respondent moves through the form; null unless the review step is on. Part of what a version freezes.
            'flow'                 => FormSnapshotService::flowOf($this->flow) ?: null,
            'status'               => $this->status,
            'visibility'           => $this->visibility,
            'responses_visibility' => $this->responses_visibility,
            'department_id'        => $this->department_id,
            'department'           => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            'created_by'           => $this->whenLoaded('createdBy', fn () => self::person($this->createdBy)),
            // The owner; for a form with no owner recorded, its creator.
            'owner'                => $this->when($this->relationLoaded('owner') || $this->relationLoaded('createdBy'), fn () => self::person($this->owner ?? $this->createdBy)),
            'last_editor'          => $this->whenLoaded('lastEditor', fn () => self::person($this->lastEditor)),
            'publisher'            => $this->whenLoaded('publisher', fn () => self::person($this->publisher)),
            'published_at'         => $this->published_at,
            'current_version'      => $version?->version_number,
            'published_version_id' => $this->published_version_id,
            // Moves on every persisted draft change; the builder sends it back as `base_revision` so a stale editor is refused.
            'draft_revision'       => (int) $this->draft_revision,
            'has_unpublished_changes' => $this->when($this->hasUnpublishedChanges !== null, fn () => $this->hasUnpublishedChanges),
            'settings'             => [
                'opens_at'             => $this->opens_at?->toIso8601String(),
                'closes_at'            => $this->closes_at?->toIso8601String(),
                'max_submissions'      => $this->max_submissions,
                'per_respondent_limit' => $this->per_respondent_limit,
                'allow_anonymous'      => (bool) $this->allow_anonymous,
                'access_mode'          => $this->accessMode(),
                'requires_share_link'  => (bool) $this->requires_share_link,
                'requires_login'       => FormAvailabilityService::requiresLogin($this->resource),
            ],
            'questions'            => FormQuestionResource::collection($this->whenLoaded('questions')),
            'submissions_count'    => $this->when($canSeeResponses, fn () => $this->whenCounted('submissions')),
            'last_submission_at'   => $this->when($canSeeResponses && array_key_exists('submissions_max_submitted_at', $this->getAttributes()), fn () => $this->getAttribute('submissions_max_submitted_at')),
            'abilities'            => $abilities,
            'created_at'           => $this->created_at,
            'updated_at'           => $this->updated_at,
        ];
    }

    /** @return array{id: int, name: string}|null */
    public static function person(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
