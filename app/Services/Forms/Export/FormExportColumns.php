<?php

namespace App\Services\Forms\Export;

use App\Models\DynamicForm;
use App\Models\FormQuestion;
use App\Models\FormSubmission;
use App\Models\FormVersion;

/**
 * The columns a form's responses can be shown or exported with, read from the PUBLISHED VERSIONS the responses were given for.
 *
 * Questions are identified by id, which is stable across versions, and take the label of the newest version that has them. With
 * a version chosen, the columns are exactly that version's questions in its order. A question that a later version dropped keeps
 * its column (its answers still exist) and is marked as removed. Responses that predate versions are covered by the form's own
 * questions, removed ones included.
 *
 * `meta` columns describe the response itself; they carry nothing the response table does not already show to the same person.
 * Internal notes, history and correction details are never columns.
 */
final class FormExportColumns
{
    /** key => [label, default selected] */
    public const META = [
        'id'              => ['رقم الرد', true],
        'submitted_at'    => ['تاريخ الإرسال', true],
        'submitter'       => ['المُرسِل', true],
        'submitter_email' => ['البريد الإلكتروني للمُرسِل', false],
        'status'          => ['الحالة', true],
        'assignee'        => ['المُسنَد إليه', true],
        'source'          => ['مصدر الإرسال', true],
        'version'         => ['رقم الإصدار', false],
        'corrected'       => ['تم تصحيح إجابات', false],
    ];

    /** Question types whose answer is a number, written as one in a spreadsheet. */
    public const NUMERIC_TYPES = ['number', 'star_rating', 'linear_scale'];

    /**
     * @return list<array{key: string, label: string, group: string, type: string|null, removed: bool, default: bool}>
     */
    public static function forForm(DynamicForm $form, ?int $versionId = null): array
    {
        $columns = [];
        foreach (self::META as $key => [$label, $default]) {
            $columns[] = ['key' => $key, 'label' => $label, 'group' => 'meta', 'type' => in_array($key, ['id', 'version'], true) ? 'number' : null, 'removed' => false, 'default' => $default];
        }

        foreach (self::questions($form, $versionId) as $question) {
            $columns[] = [
                'key'     => 'q_'.$question['id'],
                'label'   => $question['removed'] ? $question['label'].' (سؤال محذوف)' : $question['label'],
                'group'   => 'question',
                'type'    => $question['type'],
                'removed' => $question['removed'],
                'default' => true,
            ];
        }

        return $columns;
    }

    /** @return list<string> */
    public static function keys(array $columns): array
    {
        return array_column($columns, 'key');
    }

    /**
     * @return list<array{id: int, label: string, type: string, removed: bool}>
     */
    private static function questions(DynamicForm $form, ?int $versionId): array
    {
        $versions = FormVersion::query()->where('form_id', $form->id)->orderBy('version_number')->get(['id', 'version_number', 'schema']);

        if ($versionId !== null) {
            $only = $versions->firstWhere('id', $versionId);

            return $only === null ? [] : self::fromSchemas([$only], removedIn: null);
        }

        $found = self::fromSchemas($versions->all(), removedIn: $versions->last());

        // Responses that predate versions: the form's own questions (removed ones too) cover their answers.
        if ($form->submissions()->whereNull('form_version_id')->exists()) {
            $known = array_column($found, 'id');
            foreach ($form->questions()->withTrashed()->get() as $q) {
                if ($q->isStructural() || in_array($q->id, $known, true)) {
                    continue;
                }
                $found[] = ['id' => (int) $q->id, 'label' => $q->label, 'type' => $q->type, 'removed' => $q->trashed(), 'order' => (int) $q->sort_order];
            }
        }

        usort($found, fn ($a, $b) => [$a['removed'], $a['order'], $a['id']] <=> [$b['removed'], $b['order'], $b['id']]);

        return array_map(fn ($q) => ['id' => $q['id'], 'label' => $q['label'], 'type' => $q['type'], 'removed' => $q['removed']], $found);
    }

    /**
     * @param  list<FormVersion>  $versions  oldest first: a later version's label wins
     * @return list<array{id: int, label: string, type: string, removed: bool, order: int}>
     */
    private static function fromSchemas(array $versions, ?FormVersion $removedIn): array
    {
        $byId = [];
        foreach ($versions as $version) {
            foreach ($version->schema['questions'] ?? [] as $q) {
                if (in_array($q['type'] ?? null, FormQuestion::STRUCTURAL, true)) {
                    continue;
                }
                $byId[(int) $q['id']] = ['id' => (int) $q['id'], 'label' => (string) $q['label'], 'type' => (string) $q['type'], 'removed' => false, 'order' => (int) ($q['sort_order'] ?? 0)];
            }
        }

        if ($removedIn !== null) {
            $current = collect($removedIn->schema['questions'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
            foreach ($byId as $id => $q) {
                $byId[$id]['removed'] = ! in_array($id, $current, true);
            }
        }

        return array_values($byId);
    }

    /** What a submission's status reads as in a file. */
    public static function statusLabel(?string $status): string
    {
        $status = FormSubmission::LEGACY_STATUS[$status ?? ''] ?? $status;

        return FormSubmission::STATUS_LABELS[$status] ?? (string) $status;
    }
}
