<?php

namespace App\Http\Resources;

use App\Models\FormAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One answer. Multi-choice answers come back as a list (they are stored as JSON text). An attachment is never
 * exposed by its storage path: the response says there is a file and its display name, and the file itself is
 * fetched through the authorized download endpoint.
 *
 * The question's label and type come from the published version the respondent filled in when one is known (so a later
 * edit of the form never relabels an old answer), and from the question row otherwise.
 *
 * @mixin FormAnswer
 */
class FormAnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return self::present($this->resource, null);
    }

    /**
     * @param  array<string, mixed>|null  $definition  the question as frozen in the respondent's version
     * @return array<string, mixed>
     */
    public static function present(FormAnswer $answer, ?array $definition): array
    {
        $question = $answer->relationLoaded('question') ? $answer->question : null;
        $type = $definition['type'] ?? $question?->type;
        $multi = in_array($type, ['checkbox', 'multiselect'], true);

        return [
            'id'               => $answer->id,
            'question_id'      => $answer->question_id,
            'question_label'   => $definition['label'] ?? $question?->label,
            'question_type'    => $type,
            'question_deleted' => $question ? $question->trashed() : false,
            // The question as the respondent's version froze it: what a correction is edited with and held to.
            'question_required' => (bool) ($definition['required'] ?? $question?->is_required ?? false),
            'question_options'  => $definition['options'] ?? $question?->options,
            'question_config'   => $definition['config'] ?? $question?->config,
            'value'            => $multi ? self::decodeList($answer->value) : $answer->value,
            'display_value'    => self::displayValue($type, $answer->value, $definition !== null ? ($definition['config'] ?? null) : $question?->config),
            'has_file'         => $answer->file_path !== null && $answer->file_path !== '',
            'file_name'        => $answer->file_path ? $answer->value : null,
            // Whether a reviewer changed what the respondent submitted. The previous value is in the protected correction history.
            'corrected'        => $answer->corrected_at !== null,
            'corrected_at'     => $answer->corrected_at,
        ];
    }

    /**
     * The answer as a person reads it: yes / no and consent in words, a rating as "3 / 5", a list joined. Null when there
     * is nothing to show. The stored `value` is always kept as it was given.
     *
     * @param  array<string, mixed>|null  $config
     */
    public static function displayValue(?string $type, ?string $value, ?array $config): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'yes_no'       => $value === 'yes' ? 'نعم' : ($value === 'no' ? 'لا' : $value),
            'consent'      => $value === 'yes' ? 'موافق' : $value,
            'star_rating'  => $value.' / '.((int) data_get($config, 'scale.max', 5)),
            'linear_scale' => $value.' (من '.((int) data_get($config, 'scale.min', 1)).' إلى '.((int) data_get($config, 'scale.max', 5)).')',
            'checkbox', 'multiselect' => implode('، ', self::decodeList($value) ?? []),
            default        => $value,
        };
    }

    /** @return array<int, string>|null */
    public static function decodeList(?string $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [$value];
    }
}
