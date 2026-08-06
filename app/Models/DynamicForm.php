<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DynamicForm extends Model
{
    protected $fillable = [
        'title', 'slug', 'description', 'type', 'department_id',
        'related_type', 'related_id', 'status', 'created_by',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(FormQuestion::class, 'form_id')->orderBy('sort_order');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class, 'form_id');
    }
}
