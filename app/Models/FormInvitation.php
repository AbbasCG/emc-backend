<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registered EMC user a restricted form is open to. It holds no token: access needs the respondent to be signed in as `user_id`.
 */
class FormInvitation extends Model
{
    protected $fillable = ['form_id', 'user_id', 'invited_by', 'revoked_at', 'revoked_by'];

    protected $casts = [
        'revoked_at' => 'datetime',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(DynamicForm::class, 'form_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
