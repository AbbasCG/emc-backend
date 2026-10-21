<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One publication of a form. Immutable: the snapshot is what respondents saw, and the answers collected under it are
 * read against it for as long as they exist. The application never updates or deletes a version, and this model refuses
 * to, so a bug or a stray query cannot rewrite history.
 */
class FormVersion extends Model
{
    protected $fillable = [
        'form_id', 'version_number', 'schema', 'schema_hash', 'question_count',
        'origin', 'note', 'created_by', 'restored_from_version_id', 'published_at',
    ];

    protected $casts = [
        'schema'       => 'array',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('A published form version is immutable.');
        });

        static::deleting(function (): never {
            throw new \LogicException('A published form version cannot be deleted.');
        });
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(DynamicForm::class, 'form_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
