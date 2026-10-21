<?php

namespace App\Services\Forms\Export;

use App\Http\Resources\FormAnswerResource;
use App\Models\DynamicForm;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Services\Forms\FormResponseQuery;

/**
 * The rows of an export: the responses FormResponseQuery selects for the filters, cell by cell for the chosen columns.
 *
 * It is the only place an export's content is produced, for every format. Each answer is read with the definition of the question
 * as it was in the response's own published version (so "yes/no", ratings and choices read as the respondent saw them), an
 * attachment is only ever its file name (never a path or address), and rows come out in submission order, a chunk at a time, so
 * the size of the form never decides how much memory the export needs.
 */
final class FormResponseDataset
{
    private const CHUNK = 500;

    /** @var list<array{key: string, label: string, group: string, type: string|null, removed: bool, default: bool}> */
    private array $columns;

    /** @var array<int, array{number: int, questions: array<int, array<string, mixed>>}> */
    private array $versions = [];

    /**
     * @param  array<string, mixed>  $filters  normalised (FormResponseQuery::normalize)
     * @param  list<string>|null  $keys  the chosen columns; null means the default selection
     */
    public function __construct(private readonly DynamicForm $form, private readonly array $filters, ?array $keys = null)
    {
        $catalog = FormExportColumns::forForm($form, $filters['version'] ?? null);
        $keys ??= array_column(array_filter($catalog, fn ($c) => $c['default']), 'key');
        $this->columns = array_values(array_filter($catalog, fn ($c) => in_array($c['key'], $keys, true)));

        foreach (FormVersion::query()->where('form_id', $form->id)->get(['id', 'version_number', 'schema']) as $version) {
            $this->versions[$version->id] = [
                'number'    => (int) $version->version_number,
                'questions' => collect($version->schema['questions'] ?? [])->keyBy('id')->all(),
            ];
        }
    }

    /** @return list<array{key: string, label: string, group: string, type: string|null, removed: bool, default: bool}> */
    public function columns(): array
    {
        return $this->columns;
    }

    public function count(): int
    {
        return FormResponseQuery::build($this->form, $this->filters)->count();
    }

    /**
     * Calls `$write` with the cells of each response, in submission order, a chunk at a time: the size of the form never decides
     * how much memory this needs. Returns how many responses were written.
     *
     * @param  callable(list<string>): void  $write
     */
    public function each(callable $write): int
    {
        $count = 0;
        FormResponseQuery::build($this->form, $this->filters)
            ->with(['answers', 'user:id,name,email', 'assignee:id,name', 'shareLink:id,label'])
            ->orderBy('form_submissions.id')
            ->chunkById(self::CHUNK, function ($chunk) use ($write, &$count) {
                foreach ($chunk as $submission) {
                    $write($this->row($submission));
                    $count++;
                }

                return true;
            }, 'form_submissions.id', 'id');

        return $count;
    }

    /** @return list<string> */
    private function row(FormSubmission $submission): array
    {
        $answers = $submission->answers->keyBy('question_id');
        $definitions = $this->versions[$submission->form_version_id]['questions'] ?? [];
        $cells = [];

        foreach ($this->columns as $column) {
            $cells[] = $column['group'] === 'meta'
                ? $this->metaCell($column['key'], $submission)
                : $this->answerCell($column, $answers->get((int) substr($column['key'], 2)), $definitions);
        }

        return $cells;
    }

    private function metaCell(string $key, FormSubmission $s): string
    {
        return match ($key) {
            'id'              => (string) $s->id,
            'submitted_at'    => $s->submitted_at?->timezone(FormResponseQuery::DISPLAY_TIMEZONE)->format('Y-m-d H:i') ?? '',
            'submitter'       => $s->user?->name ?? 'زائر',
            'submitter_email' => $s->user?->email ?? '',
            'status'          => FormExportColumns::statusLabel($s->status),
            'assignee'        => $s->assignee?->name ?? '',
            'source'          => $s->shareLink ? 'رابط مشاركة: '.$s->shareLink->label : ($s->share_link_id ? 'رابط مشاركة' : 'الرابط العام'),
            'version'         => isset($this->versions[$s->form_version_id]) ? (string) $this->versions[$s->form_version_id]['number'] : '',
            'corrected'       => $s->corrected_at !== null ? 'نعم' : 'لا',
            default           => '',
        };
    }

    /**
     * @param  array{key: string, type: string|null}  $column
     * @param  array<int, array<string, mixed>>  $definitions
     */
    private function answerCell(array $column, $answer, array $definitions): string
    {
        if ($answer === null) {
            return '';
        }
        $id = (int) substr($column['key'], 2);
        $definition = $definitions[$id] ?? ['type' => $column['type']];
        $shown = FormAnswerResource::present($answer, $definition);

        if ($shown['has_file']) {
            return (string) ($shown['file_name'] ?? 'مرفق');
        }

        // Ratings and numbers are exported as the number itself ("4"), not as the sentence the screen shows ("4 / 5").
        if (in_array($shown['question_type'], FormExportColumns::NUMERIC_TYPES, true)) {
            return (string) ($shown['value'] ?? '');
        }

        return (string) ($shown['display_value'] ?? '');
    }
}
