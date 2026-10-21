<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An employee a form is explicitly shared with. */
class FormCollaborator extends Model
{
    public const VIEWER = 'viewer';

    public const EDITOR = 'editor';

    public const LEVELS = [self::VIEWER, self::EDITOR];

    protected $fillable = ['form_id', 'user_id', 'access_level', 'can_view_responses', 'can_correct_responses', 'granted_by'];

    protected $casts = [
        'can_view_responses'    => 'boolean',
        'can_correct_responses' => 'boolean',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(DynamicForm::class, 'form_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
