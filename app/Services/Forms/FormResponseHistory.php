<?php

namespace App\Services\Forms;

use App\Http\Resources\FormAnswerResource;
use App\Models\FormQuestion;
use App\Models\FormSubmission;
use App\Models\FormSubmissionEvent;
use App\Models\FormSubmissionNote;
use Illuminate\Support\Collection;

/**
 * A response's history as the review screen reads it: oldest first, one line per status change, assignment, note and correction.
 * A correction line carries the protected before/after of every answer it changed, labelled with the question as it was in the
 * response's own published version. Only reviewers are ever given this (the controller checks), never a respondent.
 */
final class FormResponseHistory
{
    public const LABELS = [
        FormSubmissionEvent::STATUS_CHANGED => 'تغيير حالة الرد',
        FormSubmissionEvent::ASSIGNED       => 'إسناد الرد',
        FormSubmissionEvent::UNASSIGNED     => 'إلغاء إسناد الرد',
        FormSubmissionEvent::CORRECTED      => 'تصحيح إجابات',
        FormSubmissionEvent::NOTE_ADDED     => 'إضافة ملاحظة داخلية',
    ];

    /**
     * @param  Collection<int, FormQuestion>  $questions  the response's questions by id (its version's)
     * @return list<array<string, mixed>>
     */
    public static function present(FormSubmission $submission, Collection $questions): array
    {
        $events = $submission->events()->with(['actor:id,name', 'corrections.corrector:id,name'])->orderBy('id')->get();

        $lines = [[
            'id' => 0, 'type' => 'submitted', 'label' => 'استلام الرد', 'actor' => null, 'from' => null, 'to' => null,
            'meta' => null, 'corrections' => [], 'created_at' => $submission->submitted_at,
        ]];

        foreach ($events as $event) {
            $lines[] = [
                'id'          => $event->id,
                'type'        => $event->type,
                'label'       => self::LABELS[$event->type] ?? $event->type,
                'actor'       => $event->actor ? ['id' => $event->actor->id, 'name' => $event->actor->name] : null,
                'from'        => $event->type === FormSubmissionEvent::STATUS_CHANGED ? self::status($event->from_value) : $event->from_value,
                'to'          => $event->type === FormSubmissionEvent::STATUS_CHANGED ? self::status($event->to_value) : $event->to_value,
                'meta'        => $event->meta,
                'corrections' => $event->corrections->map(fn ($c) => self::correction($c, $questions->get((int) $c->question_id)))->values()->all(),
                'created_at'  => $event->created_at,
            ];
        }

        return $lines;
    }

    /** @return list<array<string, mixed>> */
    public static function notes(FormSubmission $submission): array
    {
        return $submission->notes()->with('author:id,name')->orderBy('id')->get()->map(fn (FormSubmissionNote $n) => [
            'id'         => $n->id,
            'body'       => $n->body,
            'author'     => $n->author ? ['id' => $n->author->id, 'name' => $n->author->name] : null,
            'created_at' => $n->created_at,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private static function correction($correction, ?FormQuestion $question): array
    {
        $type = $question?->type;
        $config = $question?->config;
        $multi = in_array($type, ['checkbox', 'multiselect'], true);

        return [
            'id'               => $correction->id,
            'question_id'      => $correction->question_id,
            'question_label'   => $question?->label,
            'question_type'    => $type,
            'previous'         => $multi ? FormAnswerResource::decodeList($correction->previous_value) : $correction->previous_value,
            'new'              => $multi ? FormAnswerResource::decodeList($correction->new_value) : $correction->new_value,
            'previous_display' => FormAnswerResource::displayValue($type, $correction->previous_value, $config),
            'new_display'      => FormAnswerResource::displayValue($type, $correction->new_value, $config),
            'reason'           => $correction->reason,
            'corrected_by'     => $correction->corrector ? ['id' => $correction->corrector->id, 'name' => $correction->corrector->name] : null,
            'created_at'       => $correction->created_at,
        ];
    }

    private static function status(?string $status): ?string
    {
        return $status === null ? null : (FormSubmission::STATUS_LABELS[FormSubmission::LEGACY_STATUS[$status] ?? $status] ?? $status);
    }
}
