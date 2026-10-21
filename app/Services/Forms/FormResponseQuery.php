<?php

namespace App\Services\Forms;

use App\Models\DynamicForm;
use App\Models\FormSubmission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;

/**
 * THE set of responses a person is looking at: one form's submissions narrowed by the screen's filters.
 *
 * The response table, the status counts and every export build their rows here, from the same normalised filters, so a file can
 * never contain a row the table would not show, nor miss one it would. The caller has already decided (FormAccessService) that
 * this person may read this form's responses; this class only narrows, it never widens: the form is always the starting point.
 *
 * Filters (all optional):
 *   search     text found in an answer, the respondent's name or e-mail, or the response number
 *   status     one or more of FormSubmission::STATUSES
 *   assignee   an employee id, `none` (unassigned) or `me`
 *   version    a published version of THIS form (id)
 *   from / to  submission dates, both ends included
 *   source     `public` (the plain address), `link` (any share link) or a link id of THIS form
 *   corrected  only responses with at least one corrected answer
 */
final class FormResponseQuery
{
    public const SORTS = ['submitted_at', 'id', 'status'];

    /** Dates are stored in UTC but read, filtered and exported in the platform's display timezone, like every EMC screen. */
    public const DISPLAY_TIMEZONE = 'Europe/Amsterdam';

    /** @return array<string, mixed> validation rules shared by the list and the export */
    public static function rules(): array
    {
        return [
            'search'    => ['nullable', 'string', 'max:200'],
            'status'    => ['nullable'],
            'status.*'  => ['string', Rule::in([...FormSubmission::STATUSES, ...array_keys(FormSubmission::LEGACY_STATUS)])],
            'assignee'  => ['nullable', 'string', 'max:12'],
            'version'   => ['nullable', 'integer', 'min:1'],
            'from'      => ['nullable', 'date_format:Y-m-d'],
            'to'        => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'source'    => ['nullable', 'string', 'max:12'],
            'corrected' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The filters as the server will apply and store them: only known keys, empty ones dropped, `me` resolved, statuses as a list.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input, User $viewer): array
    {
        $out = [];

        $search = trim((string) ($input['search'] ?? ''));
        if ($search !== '') {
            $out['search'] = mb_substr($search, 0, 200);
        }

        $status = $input['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $status = explode(',', $status);
        }
        if (is_array($status)) {
            $list = array_values(array_unique(array_filter(array_map(
                fn ($s) => FormSubmission::LEGACY_STATUS[$s] ?? $s,
                array_map('strval', $status),
            ), fn ($s) => in_array($s, FormSubmission::STATUSES, true))));
            if ($list !== []) {
                $out['status'] = $list;
            }
        }

        $assignee = (string) ($input['assignee'] ?? '');
        if ($assignee === 'me') {
            $out['assignee'] = (int) $viewer->id;
        } elseif ($assignee === 'none') {
            $out['assignee'] = 'none';
        } elseif (ctype_digit($assignee) && (int) $assignee > 0) {
            $out['assignee'] = (int) $assignee;
        }

        if (! empty($input['version']) && ctype_digit((string) $input['version'])) {
            $out['version'] = (int) $input['version'];
        }
        foreach (['from', 'to'] as $edge) {
            if (! empty($input[$edge]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input[$edge]) === 1) {
                $out[$edge] = (string) $input[$edge];
            }
        }

        $source = (string) ($input['source'] ?? '');
        if ($source === 'public' || $source === 'link') {
            $out['source'] = $source;
        } elseif (ctype_digit($source) && (int) $source > 0) {
            $out['source'] = (int) $source;
        }

        if (filter_var($input['corrected'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $out['corrected'] = true;
        }

        return $out;
    }

    /**
     * The responses of the form that match the filters, newest first unless asked otherwise.
     *
     * @param  array<string, mixed>  $filters  already normalised
     * @return Builder<FormSubmission>
     */
    public static function build(DynamicForm $form, array $filters, bool $withStatus = true): Builder
    {
        $query = FormSubmission::query()->where('form_submissions.form_id', $form->id);

        if ($withStatus && ! empty($filters['status'])) {
            // A response still stored under the old `reviewed` spelling is a completed one.
            $wanted = in_array(FormSubmission::COMPLETED, $filters['status'], true) ? [...$filters['status'], ...array_keys(FormSubmission::LEGACY_STATUS)] : $filters['status'];
            $query->whereIn('form_submissions.status', $wanted);
        }

        if (isset($filters['assignee'])) {
            $filters['assignee'] === 'none'
                ? $query->whereNull('form_submissions.assigned_to')
                : $query->where('form_submissions.assigned_to', (int) $filters['assignee']);
        }

        if (isset($filters['version'])) {
            // A version of another form simply matches nothing: the form is always the starting point.
            $query->where('form_submissions.form_version_id', (int) $filters['version']);
        }

        // "From 15 March" means from the first moment of the 15th where people read the dates, whatever the database stores.
        if (isset($filters['from'])) {
            $query->where('form_submissions.submitted_at', '>=', CarbonImmutable::parse($filters['from'], self::DISPLAY_TIMEZONE)->startOfDay()->utc());
        }
        if (isset($filters['to'])) {
            $query->where('form_submissions.submitted_at', '<=', CarbonImmutable::parse($filters['to'], self::DISPLAY_TIMEZONE)->endOfDay()->utc());
        }

        if (isset($filters['source'])) {
            match (true) {
                $filters['source'] === 'public' => $query->whereNull('form_submissions.share_link_id'),
                $filters['source'] === 'link'   => $query->whereNotNull('form_submissions.share_link_id'),
                default                         => $query->where('form_submissions.share_link_id', (int) $filters['source']),
            };
        }

        if (! empty($filters['corrected'])) {
            $query->whereNotNull('form_submissions.corrected_at');
        }

        if (isset($filters['search'])) {
            self::search($query, (string) $filters['search']);
        }

        return $query;
    }

    /** @param  Builder<FormSubmission>  $query */
    private static function search(Builder $query, string $term): void
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $w) use ($like, $term) {
            $w->whereExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('form_answers')
                ->whereColumn('form_answers.submission_id', 'form_submissions.id')->where('form_answers.value', 'like', $like))
                ->orWhereExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('users')
                    ->whereColumn('users.id', 'form_submissions.user_id')
                    ->where(fn (QueryBuilder $u) => $u->where('users.name', 'like', $like)->orWhere('users.email', 'like', $like)));
            if (ctype_digit($term)) {
                $w->orWhere('form_submissions.id', (int) $term);
            }
        });
    }

    /**
     * Newest first by default. Always ends on the id so pages never overlap or skip a row when dates tie.
     *
     * @param  Builder<FormSubmission>  $query
     */
    public static function sort(Builder $query, ?string $sort, ?string $direction): Builder
    {
        $column = in_array($sort, self::SORTS, true) ? $sort : 'submitted_at';
        $dir = strtolower((string) $direction) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy('form_submissions.'.$column, $dir)->orderBy('form_submissions.id', $dir);
    }

    /**
     * How many responses are in each status for the same filters, ignoring the status filter itself, so the status chips keep
     * showing what choosing each would give.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public static function statusCounts(DynamicForm $form, array $filters): array
    {
        $rows = self::build($form, $filters, withStatus: false)->reorder()
            ->selectRaw('form_submissions.status as s, count(*) as c')->groupBy('form_submissions.status')->pluck('c', 's');

        $counts = array_fill_keys(FormSubmission::STATUSES, 0);
        foreach ($rows as $stored => $count) {
            $status = FormSubmission::LEGACY_STATUS[$stored] ?? $stored;
            // Anything stored under a spelling outside the workflow still counts as "new" for the chips.
            $counts[in_array($status, FormSubmission::STATUSES, true) ? $status : FormSubmission::NEW] += (int) $count;
        }

        return $counts;
    }
}
