<?php

namespace App\Services\Forms;

use App\Models\DynamicForm;
use App\Models\FormQuestion;
use App\Services\Forms\Logic\FormLogic;
use Illuminate\Validation\ValidationException;

/**
 * Saves a form's block list from one payload, so creating or updating a form can never silently drop the blocks the
 * editor sent.
 *
 * Matching is by id AND form: an id that is not one of THIS form's questions (the editor's temporary ids, or an id copied
 * from another form) is simply a new question — it can never touch another form's rows. An id that belongs to this form
 * but was removed earlier brings that very row back (so undoing a delete in the builder keeps the answers attached to it).
 * The position in the payload is the order. A block missing from the payload is soft-deleted, so any answers already given
 * stay attached to it and remain readable.
 *
 * It returns what actually changed (added, removed, edited, reordered), which is what the activity history records and
 * what decides whether the form's "last edited by" and draft revision move: nothing changed, nothing is recorded.
 */
class FormQuestionSyncService
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{added: list<string>, removed: list<string>, edited: list<string>, reordered: bool, changed: bool}
     */
    public function sync(DynamicForm $form, array $items): array
    {
        $items = array_values($items);
        $normalized = self::normalizeAll($items);

        $existing = FormQuestion::withTrashed()->where('form_id', $form->id)->get()->keyBy('id');
        $live = $existing->filter(fn (FormQuestion $q) => ! $q->trashed());
        // The order before anything is touched (the loop below updates these same objects).
        $oldOrder = $live->sortBy([['sort_order', 'asc'], ['id', 'asc']])->keys()->all();
        $keep = [];
        $added = $edited = [];
        $newOrder = [];
        /** @var array<int, FormQuestion> $rows the stored block of every payload item, by position */
        $rows = [];
        /** @var array<int, int> $idMap the id the editor used for a block (its temporary id included) => the stored id */
        $idMap = [];

        foreach ($items as $index => $item) {
            $attributes = $normalized[$index] + ['sort_order' => $index + 1];
            $current = isset($item['id']) ? $existing->get((int) $item['id']) : null;

            if ($current instanceof FormQuestion && ! in_array($current->id, $keep, true)) {
                if ($current->type !== $attributes['type'] && $current->answers()->exists()) {
                    throw ValidationException::withMessages([
                        "questions.$index.type" => 'لا يمكن تغيير نوع سؤال لديه إجابات مسجّلة. أضف سؤالاً جديداً بدلاً من ذلك.',
                    ]);
                }

                $wasTrashed = $current->trashed();
                if ($wasTrashed) {
                    $current->restore();
                }
                $contentChanged = $wasTrashed || self::differs($current, $attributes);
                if ($wasTrashed) {
                    $added[] = $attributes['label'] !== '' ? $attributes['label'] : $attributes['type'];
                } elseif ($contentChanged) {
                    $edited[] = $attributes['label'] !== '' ? $attributes['label'] : $attributes['type'];
                }

                $current->update($attributes);
                $keep[] = $current->id;
                $newOrder[] = $current->id;
                $rows[$index] = $current;
                $this->remember($idMap, $item, $current->id);

                continue;
            }

            $created = $form->questions()->create($attributes);
            $keep[] = $created->id;
            $added[] = $attributes['label'] !== '' ? $attributes['label'] : $attributes['type'];
            $rows[$index] = $created;
            $this->remember($idMap, $item, $created->id);
        }

        $this->applyLogic($items, $normalized, $rows, $idMap, $added, $edited);

        $removed = [];
        foreach ($live as $id => $question) {
            if (! in_array($id, $keep, true)) {
                $removed[] = $question->label !== '' ? $question->label : $question->type;
                $question->delete(); // soft delete: its answers stay
            }
        }

        // Reordered: the blocks that were and still are, in a different relative order than before.
        $before = array_values(array_filter($oldOrder, fn ($id) => in_array($id, $newOrder, true)));
        $reordered = $before !== $newOrder;

        return [
            'added'     => $added,
            'removed'   => $removed,
            'edited'    => $edited,
            'reordered' => $reordered,
            'changed'   => $added !== [] || $removed !== [] || $edited !== [] || $reordered,
        ];
    }

    /**
     * Remembers which stored block an item of the payload became, under every name the editor may use for it: its `id` (a stored
     * block's id, or a temporary one) and its `ref` (the temporary id the editor gave an unsaved block that a rule reads).
     *
     * @param  array<int, int>  $idMap
     * @param  array<string, mixed>  $item
     */
    private function remember(array &$idMap, array $item, int $storedId): void
    {
        foreach (['id', 'ref'] as $name) {
            if (isset($item[$name]) && is_numeric($item[$name])) {
                $idMap[(int) $item[$name]] ??= $storedId;
            }
        }
    }

    /**
     * Stores the visibility rules, once every block of the payload has its stored id (a rule may read a block that is created in
     * this very save, which the editor knows only by its temporary id). A rule whose shape is wrong, or that reads a block that is
     * not in the payload, refuses the whole save with `questions.<position>.logic...` messages; the caller's transaction undoes the
     * rest. An item without a `logic` key leaves the stored rule as it is (a client that does not know about rules does not erase them).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $normalized
     * @param  array<int, FormQuestion>  $rows
     * @param  array<int, int>  $idMap
     * @param  list<string>  $added
     * @param  list<string>  $edited
     */
    private function applyLogic(array $items, array $normalized, array $rows, array $idMap, array $added, array &$edited): void
    {
        $sources = [];
        foreach ($rows as $index => $row) {
            $sources[$row->id] = ['type' => $normalized[$index]['type'], 'options' => $normalized[$index]['options'] ?? null];
        }

        $errors = [];
        foreach ($rows as $index => $row) {
            $item = (array) $items[$index];
            if (! array_key_exists('logic', $item)) {
                continue;
            }
            [$logic, $logicErrors] = FormLogic::normalize($item['logic'], $sources, $idMap);
            foreach ($logicErrors as $field => $message) {
                $errors["questions.$index.$field"] = $message;
            }
            if ($logicErrors !== [] || json_encode($row->logic) === json_encode($logic)) {
                continue;
            }
            $row->forceFill(['logic' => $logic])->save();
            $label = $normalized[$index]['label'] !== '' ? $normalized[$index]['label'] : $normalized[$index]['type'];
            if (! in_array($label, $added, true) && ! in_array($label, $edited, true)) {
                $edited[] = $label;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The stored columns of every item, or a 422 naming every field that is wrong (`questions.<position>.<field>`).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeAll(array $items): array
    {
        [$out, $errors] = self::inspect($items);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * Like normalizeAll, but only reports: every error by `questions.<position>.<field>`. Items with a type the builder does
     * not know are left to the type rule of the request (so one bad type does not hide the other mistakes).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, string>
     */
    public static function errorsFor(array $items): array
    {
        return self::inspect($items, skipUnknown: true)[1];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>}
     */
    private static function inspect(array $items, bool $skipUnknown = false): array
    {
        $out = [];
        $errors = [];
        foreach (array_values($items) as $index => $item) {
            $item = (array) $item;
            if ($skipUnknown && ! in_array($item['type'] ?? null, FormQuestion::TYPES, true)) {
                continue;
            }
            ['attributes' => $attributes, 'errors' => $itemErrors] = FormQuestionConfig::normalize($item);
            foreach ($itemErrors as $field => $message) {
                $errors["questions.$index.$field"] = $message;
            }
            if (in_array($attributes['type'], ['select', 'radio', 'multiselect'], true) && $attributes['options'] === null) {
                $errors["questions.$index.options"] = 'أضف خياراً واحداً على الأقل لهذا السؤال.';
            }
            $out[$index] = $attributes;
        }

        return [$out, $errors];
    }

    /**
     * The columns of one question from a payload item (either spelling of the required flag).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function attributes(array $item, int $sortOrder): array
    {
        ['attributes' => $attributes, 'errors' => $errors] = FormQuestionConfig::normalize($item);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $attributes + ['sort_order' => $sortOrder];
    }

    /** Does a stored question differ from what the builder sent, in anything a respondent or an editor can see? */
    private static function differs(FormQuestion $current, array $attributes): bool
    {
        foreach (['label', 'type', 'description', 'placeholder', 'help_text'] as $field) {
            if ((string) $current->{$field} !== (string) ($attributes[$field] ?? '')) {
                return true;
            }
        }

        return (array) ($current->options ?? []) !== (array) ($attributes['options'] ?? [])
            || (bool) $current->is_required !== (bool) $attributes['is_required']
            || json_encode($current->config ?? null) !== json_encode($attributes['config'] ?? null);
    }

    /**
     * Options only mean something for choice questions. Trimmed, de-duplicated, empty ones dropped; null when none.
     *
     * @return array<int, string>|null
     */
    public static function normalizeOptions(string $type, mixed $options): ?array
    {
        if (! in_array($type, [...FormQuestion::CHOICE_SINGLE, ...FormQuestion::CHOICE_MULTI], true) || ! is_array($options)) {
            return null;
        }

        $clean = [];
        foreach ($options as $option) {
            if (is_string($option) && trim($option) !== '') {
                $clean[trim($option)] = true;
            }
        }

        return $clean === [] ? null : array_map('strval', array_keys($clean));
    }
}
