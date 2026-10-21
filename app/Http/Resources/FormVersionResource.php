<?php

namespace App\Http\Resources;

use App\Models\FormVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One published version. The list carries the facts about the publication; the detail also carries the frozen
 * questions exactly as respondents saw them.
 *
 * @mixin FormVersion
 */
class FormVersionResource extends JsonResource
{
    private bool $withSchema = false;

    private ?int $currentVersionId = null;

    public function withSchema(): static
    {
        $this->withSchema = true;

        return $this;
    }

    public function markingCurrent(?int $publishedVersionId): static
    {
        $this->currentVersionId = $publishedVersionId;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'version_number'           => $this->version_number,
            'origin'                   => $this->origin,
            'note'                     => $this->note,
            'question_count'           => $this->question_count,
            'published_at'             => $this->published_at,
            'publisher'                => $this->whenLoaded('publisher', fn () => DynamicFormResource::person($this->publisher)),
            'restored_from_version_id' => $this->restored_from_version_id,
            'is_current'               => $this->currentVersionId !== null && $this->currentVersionId === $this->id,
            'title'                    => $this->when($this->withSchema, fn () => $this->schema['form']['title'] ?? null),
            'description'              => $this->when($this->withSchema, fn () => $this->schema['form']['description'] ?? null),
            'questions'                => $this->when($this->withSchema, fn () => array_values(array_map(fn (array $q) => [
                'id' => $q['id'], 'label' => $q['label'], 'type' => $q['type'], 'options' => $q['options'] ?? null,
                'required' => (bool) $q['required'], 'is_required' => (bool) $q['required'], 'sort_order' => $q['sort_order'],
                'description' => $q['description'] ?? null, 'placeholder' => $q['placeholder'] ?? null,
                'help_text' => $q['help_text'] ?? null, 'config' => $q['config'] ?? null,
            ], $this->schema['questions'] ?? []))),
            'settings'                 => $this->when($this->withSchema, fn () => $this->schema['settings'] ?? []),
        ];
    }
}
