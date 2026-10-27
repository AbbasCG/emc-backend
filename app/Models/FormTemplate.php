<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A form template saved by an employee (Smart Forms Phase 6). The built-in templates are not rows: see SystemFormTemplates.
 *
 * @property array{title: string, description: ?string, type: ?string, flow: ?array, questions: list<array<string, mixed>>} $definition
 */
class FormTemplate extends Model
{
    use SoftDeletes;

    public const VISIBILITIES = ['private', 'department', 'organization'];

    public const CATEGORIES = ['education', 'feedback', 'community', 'hr', 'finance', 'partnerships', 'events', 'support', 'general'];

    protected $fillable = [
        'name', 'description', 'category', 'form_type', 'definition', 'visibility', 'department_id',
        'owner_id', 'created_by', 'updated_by', 'source_form_id', 'uses_count',
    ];

    protected $attributes = [
        'visibility' => 'private',
        'category'   => 'general',
        'uses_count' => 0,
    ];

    protected $casts = [
        'definition' => 'array',
        'uses_count' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function sourceForm(): BelongsTo
    {
        return $this->belongsTo(DynamicForm::class, 'source_form_id');
    }
}
