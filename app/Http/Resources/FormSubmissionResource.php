<?php

namespace App\Http\Resources;

use App\Models\FormSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One submission as the response list shows it. `submitter_label` and `answers_preview` are what the list's
 * columns read; `answers` carries the full detail, labelled as the respondent saw the form (their version).
 *
 * @mixin FormSubmission
 */
class FormSubmissionResource extends JsonResource
{
    private const PREVIEW_ENTRIES = 3;

    private const PREVIEW_MAX_CHARS = 160;

    public function toArray(Request $request): array
    {
        $definitions = $this->definitions();
        $answers = $this->relationLoaded('answers')
            ? $this->answers->map(fn ($a) => FormAnswerResource::present($a, $definitions[$a->question_id] ?? null))->values()->all()
            : null;

        return [
            'id'              => $this->id,
            'form_id'         => $this->form_id,
            'form_version_id' => $this->form_version_id,
            'form_version'    => $this->relationLoaded('version') ? $this->version?->version_number : null,
            'status'          => FormSubmission::LEGACY_STATUS[$this->status] ?? $this->status,
            'status_label'    => FormSubmission::STATUS_LABELS[FormSubmission::LEGACY_STATUS[$this->status] ?? $this->status] ?? $this->status,
            // Who the response is assigned to: a name and an id, nothing else about the employee.
            'assignee'        => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'assigned_at'     => $this->assigned_at,
            'corrected'       => $this->corrected_at !== null,
            'corrected_at'    => $this->corrected_at,
            'source'          => ['type' => $this->share_link_id ? 'link' : 'public', 'label' => $this->relationLoaded('shareLink') && $this->shareLink ? $this->shareLink->label : null],
            // The share link the response came through, by the label its managers gave it (never the token).
            'share_link'      => $this->whenLoaded('shareLink', fn () => $this->shareLink ? ['id' => $this->shareLink->id, 'label' => $this->shareLink->label] : null),
            'submitted_at'    => $this->submitted_at,
            'metadata'        => $this->metadata,
            'submitter_label' => $this->relationLoaded('user') ? ($this->user?->name ?? 'زائر') : null,
            'user'            => $this->whenLoaded('user', fn () => $this->user ? [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ] : null),
            'answers_preview' => $this->when($answers !== null, fn () => $this->preview($answers)),
            'answers'         => $this->when($answers !== null, fn () => $answers),
            'created_at'      => $this->created_at,
        ];
    }

    /** @return array<int, array<string, mixed>> the frozen questions of this submission's version, by question id */
    private function definitions(): array
    {
        if (! $this->relationLoaded('version') || $this->version === null) {
            return [];
        }

        return collect($this->version->schema['questions'] ?? [])->keyBy('id')->all();
    }

    /** @param  list<array<string, mixed>>  $answers */
    private function preview(array $answers): string
    {
        $parts = [];
        foreach ($answers as $answer) {
            $shown = $answer['value'];
            if (is_array($shown)) {
                $shown = implode('، ', $shown);
            }
            if ($shown === null || $shown === '') {
                continue;
            }
            $parts[] = ($answer['question_label'] ? $answer['question_label'].': ' : '').$shown;
            if (count($parts) >= self::PREVIEW_ENTRIES) {
                break;
            }
        }

        return mb_strimwidth(implode(' · ', $parts), 0, self::PREVIEW_MAX_CHARS, '…');
    }
}
