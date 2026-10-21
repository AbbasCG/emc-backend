<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A form-specific share link. Only the hash of its token is stored; the token is shown once, when the link is made.
 *
 * `max_uses` limits the SUBMISSIONS made through the link, not the times it is opened: a person who opens a link and does not
 * answer uses nothing. Expiry is judged when the answer is submitted, so a link that expires while someone is filling the form
 * refuses that answer with a clear message.
 */
class FormShareLink extends Model
{
    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const EXHAUSTED = 'exhausted';

    protected $fillable = ['form_id', 'label', 'token_hash', 'token_hint', 'expires_at', 'max_uses', 'created_by', 'revoked_at', 'revoked_by'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'max_uses'   => 'integer',
    ];

    /** A new random token: 48 alphanumeric characters (about 285 bits). */
    public static function newToken(): string
    {
        return Str::random(48);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(DynamicForm::class, 'form_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class, 'share_link_id');
    }

    /** Where the link stands. `$uses` is the number of submissions already made through it. */
    public function statusFor(int $uses): string
    {
        if ($this->revoked_at !== null) {
            return self::REVOKED;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return self::EXPIRED;
        }
        if ($this->max_uses !== null && $uses >= $this->max_uses) {
            return self::EXHAUSTED;
        }

        return self::ACTIVE;
    }
}
