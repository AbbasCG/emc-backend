<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingParticipant extends Model
{
    public const SOURCE_INVITED = 'invited';
    public const SOURCE_SELF = 'self';
    public const SOURCE_ROSTER = 'roster';

    public const STATUS_INVITED = 'invited';
    public const STATUS_JOINED = 'joined';
    public const STATUS_ATTENDED = 'attended';
    public const STATUS_ABSENT = 'absent';

    public const EXCUSE_WITH = 'with_excuse';
    public const EXCUSE_WITHOUT = 'without_excuse';

    protected $fillable = [
        'meeting_id', 'user_id', 'source', 'status',
        'excuse_status', 'excuse_reason',
        'joined_at', 'confirmed_at', 'marked_by',
    ];

    protected $casts = [
        'joined_at'    => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Counted as having been present: joined/confirmed, or marked attended in the report. */
    public function present(): bool
    {
        return in_array($this->status, [self::STATUS_JOINED, self::STATUS_ATTENDED], true);
    }
}
