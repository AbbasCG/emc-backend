<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormQuestion extends Model
{
    protected $fillable = [
        'form_id', 'label', 'type', 'options', 'is_required', 'sort_order',
    ];

    protected $casts = [
        'options'     => 'array',
        'is_required' => 'boolean',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(DynamicForm::class, 'form_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class, 'question_id');
    }
}
