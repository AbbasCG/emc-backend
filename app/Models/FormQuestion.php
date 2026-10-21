<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One block of a form: either a question a respondent answers, or a structural block (section heading, descriptive text,
 * page break) that only shapes how the form reads. Structural blocks never produce an answer row.
 */
class FormQuestion extends Model
{
    // Soft-deleted: a removed question keeps its answers, which stay readable in the response list.
    use SoftDeletes;

    /** Blocks that shape the form but are not answered. */
    public const STRUCTURAL = ['section', 'description', 'page_break'];

    /** Every type a respondent can answer. */
    public const INPUT_TYPES = [
        'text', 'textarea', 'email', 'phone', 'number', 'url', 'date', 'time',
        'radio', 'checkbox', 'select', 'multiselect', 'yes_no',
        'star_rating', 'linear_scale', 'consent', 'file', 'image',
    ];

    /** Everything the builder can save. */
    public const TYPES = [...self::INPUT_TYPES, ...self::STRUCTURAL];

    /** Types whose answer must be one of the configured options. */
    public const CHOICE_SINGLE = ['select', 'radio'];

    /** Types whose answer is a list of configured options. */
    public const CHOICE_MULTI = ['multiselect', 'checkbox'];

    /** The options a checkbox falls back to when none are configured (the form builder's historic default). */
    public const CHECKBOX_DEFAULT_OPTIONS = ['نعم', 'لا'];

    protected $fillable = [
        'form_id', 'label', 'type', 'options', 'is_required', 'sort_order',
        'description', 'placeholder', 'help_text', 'config', 'logic',
    ];

    protected $casts = [
        'options'     => 'array',
        'is_required' => 'boolean',
        'config'      => 'array',
        'logic'       => 'array',
    ];

    public function isStructural(): bool
    {
        return in_array($this->type, self::STRUCTURAL, true);
    }

    public function isChoice(): bool
    {
        return in_array($this->type, [...self::CHOICE_SINGLE, ...self::CHOICE_MULTI], true);
    }

    public function isMulti(): bool
    {
        return in_array($this->type, self::CHOICE_MULTI, true);
    }

    /** A setting from the question's config bag, by dotted path (e.g. `scale.max`). */
    public function setting(string $path, mixed $default = null): mixed
    {
        return data_get($this->config ?? [], $path, $default);
    }

    /** The options a respondent may pick, with the checkbox fallback applied. */
    public function allowedOptions(): array
    {
        $options = array_values(array_filter((array) ($this->options ?? []), fn ($o) => is_string($o) && $o !== ''));

        if ($options === [] && $this->type === 'checkbox') {
            return self::CHECKBOX_DEFAULT_OPTIONS;
        }

        return $options;
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(DynamicForm::class, 'form_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class, 'question_id');
    }
}
