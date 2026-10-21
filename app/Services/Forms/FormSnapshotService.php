<?php

namespace App\Services\Forms;

use App\Models\DynamicForm;
use App\Models\FormQuestion;
use Illuminate\Support\Collection;

/**
 * The canonical, comparable description of a form's content, and the snapshot stored in a published version.
 *
 * A snapshot has two parts:
 *  - CONTENT: what a respondent sees and what answers are validated against: the title, description, type, and every
 *    question with its id, label, type, options, required flag and position. This part is hashed.
 *  - RECORD: the availability settings and the validation limits in force when it was published. Kept for history only;
 *    they are not hashed (changing a closing date is not a content change) and are not read back to enforce anything.
 *
 * `hasUnpublishedChanges()` compares the hash of the working draft with the hash of the published version, so
 * "the draft differs from what respondents see" is a fact computed from data, never a flag someone must remember to set.
 *
 * The static builders take plain arrays or rows so the legacy backfill migration can use exactly the same format.
 */
class FormSnapshotService
{
    public const FORMAT = 1;

    /** @return array<string, mixed> the hashed content block */
    public static function content(array|object $form, iterable $questions): array
    {
        $form = (array) $form;
        $list = [];
        foreach ($questions as $q) {
            $list[] = self::normalizeQuestion($q);
        }
        usort($list, fn ($a, $b) => [$a['sort_order'], $a['id']] <=> [$b['sort_order'], $b['id']]);

        $meta = [
            'title'       => (string) ($form['title'] ?? ''),
            'description' => isset($form['description']) && $form['description'] !== '' ? (string) $form['description'] : null,
            'type'        => (string) ($form['type'] ?? 'general'),
        ];
        // The respondent's flow (today: the review step) joins the content only when something is switched on, so no existing
        // form or version changes its hash.
        $flow = self::flowOf($form['flow'] ?? null);
        if ($flow !== []) {
            $meta['flow'] = $flow;
        }

        return [
            'format'    => self::FORMAT,
            'form'      => $meta,
            'questions' => $list,
        ];
    }

    /**
     * The flow settings that are switched on, from a form row's JSON (raw string or array). Anything unknown or off is dropped.
     *
     * @return array<string, true>
     */
    public static function flowOf(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) && ! empty($raw['review_step']) ? ['review_step' => true] : [];
    }

    public static function hash(array $content): string
    {
        return sha1((string) json_encode(
            ['format' => $content['format'] ?? self::FORMAT, 'form' => $content['form'] ?? [], 'questions' => $content['questions'] ?? []],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * The full snapshot: content plus the record of settings and limits.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function build(array|object $form, iterable $questions, array $settings = []): array
    {
        return self::content($form, $questions) + [
            'settings' => $settings,
            'rules'    => [
                'text_max'      => FormSubmissionService::TEXT_MAX,
                'textarea_max'  => FormSubmissionService::TEXTAREA_MAX,
                'file_max_kb'   => \App\Rules\FormAttachmentFile::MAX_KB,
                'file_types'    => array_keys(\App\Rules\FormAttachmentFile::ALLOWED),
                'metadata_max'  => FormSubmissionService::METADATA_MAX_BYTES,
            ],
        ];
    }

    /** One question in its canonical shape, from a model, a row or an array. */
    public static function normalizeQuestion(array|object $q): array
    {
        $q = (array) (is_object($q) && method_exists($q, 'getAttributes') ? $q->getAttributes() : $q);

        $options = $q['options'] ?? null;
        if (is_string($options)) {
            $options = json_decode($options, true);
        }
        $options = is_array($options) && $options !== [] ? array_values(array_map('strval', $options)) : null;

        $canonical = [
            'id'         => (int) $q['id'],
            'label'      => (string) $q['label'],
            'type'       => (string) $q['type'],
            'options'    => $options,
            'required'   => (bool) ($q['is_required'] ?? $q['required'] ?? false),
            'sort_order' => (int) ($q['sort_order'] ?? 0),
        ];

        // Builder properties are added ONLY when set. A question that has none (every question that existed before the builder)
        // keeps exactly the shape and the hash it always had, so no existing form is suddenly "changed" by this feature.
        foreach (['description', 'placeholder', 'help_text'] as $field) {
            if (isset($q[$field]) && $q[$field] !== '') {
                $canonical[$field] = (string) $q[$field];
            }
        }
        $config = $q['config'] ?? null;
        if (is_string($config)) {
            $config = json_decode($config, true);
        }
        if (is_array($config) && $config !== []) {
            $canonical['config'] = $config;
        }
        // The visibility rule, only when there is one: a block without a rule keeps exactly the shape and hash it always had.
        $logic = $q['logic'] ?? null;
        if (is_string($logic)) {
            $logic = json_decode($logic, true);
        }
        if (is_array($logic) && ! empty($logic['conditions'])) {
            $canonical['logic'] = self::canonicalLogic($logic);
        }

        return $canonical;
    }

    /**
     * A rule with its keys in one fixed order, so the hash of a block never depends on how a rule happened to be assembled
     * (a restore, a client, a migration).
     *
     * @param  array<string, mixed>  $logic
     * @return array<string, mixed>
     */
    public static function canonicalLogic(array $logic): array
    {
        $conditions = [];
        foreach ($logic['conditions'] ?? [] as $c) {
            $one = ['question' => (int) ($c['question'] ?? 0), 'operator' => (string) ($c['operator'] ?? '')];
            if (array_key_exists('value', $c)) {
                $one['value'] = $c['value'];
            }
            $conditions[] = $one;
        }

        return ['action' => (string) ($logic['action'] ?? 'show'), 'match' => (string) ($logic['match'] ?? 'all'), 'conditions' => $conditions];
    }

    /** The settings that are recorded with a version. */
    public static function settingsRecord(array|object $form): array
    {
        $form = (array) $form;

        return [
            'allow_anonymous'      => (bool) ($form['allow_anonymous'] ?? true),
            'per_respondent_limit' => $form['per_respondent_limit'] ?? null,
            'max_submissions'      => $form['max_submissions'] ?? null,
            'opens_at'             => isset($form['opens_at']) ? (string) $form['opens_at'] : null,
            'closes_at'            => isset($form['closes_at']) ? (string) $form['closes_at'] : null,
        ];
    }

    /** The working draft of a form, as a full snapshot. */
    public function forForm(DynamicForm $form): array
    {
        return self::build($form->getAttributes(), $form->questions()->get(), self::settingsRecord($form->getAttributes()));
    }

    /** Hash of the working draft's content. */
    public function draftHash(DynamicForm $form): string
    {
        return self::hash(self::content($form->getAttributes(), $form->questions()->get()));
    }

    /** True when the draft differs from the published version (or nothing has been published yet). */
    public function hasUnpublishedChanges(DynamicForm $form): bool
    {
        $version = $form->publishedVersion;

        return $version === null || $version->schema_hash !== $this->draftHash($form);
    }

    /**
     * Unsaved FormQuestion models for a snapshot, so the submission validator can treat a historical version exactly
     * like live questions without any of its code knowing the difference.
     *
     * @param  array<string, mixed>  $snapshot
     * @return Collection<int, FormQuestion>
     */
    public static function questionModels(array $snapshot): Collection
    {
        return collect($snapshot['questions'] ?? [])->map(function (array $q) {
            $model = new FormQuestion;
            $model->forceFill([
                'id'          => $q['id'],
                'label'       => $q['label'],
                'type'        => $q['type'],
                'options'     => $q['options'] ?? null,
                'is_required' => (bool) ($q['required'] ?? false),
                'sort_order'  => $q['sort_order'] ?? 0,
                'description' => $q['description'] ?? null,
                'placeholder' => $q['placeholder'] ?? null,
                'help_text'   => $q['help_text'] ?? null,
                'config'      => $q['config'] ?? null,
                'logic'       => $q['logic'] ?? null,
            ]);

            return $model;
        })->values();
    }
}
