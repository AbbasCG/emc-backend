<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meeting extends Model
{
    protected $fillable = [
        'title', 'description', 'type', 'department_id', 'organizer_id',
        'meeting_date', 'start_time', 'end_time', 'meeting_url', 'status',
        'agenda', 'minutes', 'attendees', 'files', 'recording_url',
    ];

    protected $casts = [
        'meeting_date' => 'date',
        'agenda'       => 'array',
        'attendees'    => 'array',
        'files'        => 'array',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(MeetingDecision::class);
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(MeetingActionItem::class);
    }

    public function report()
    {
        return $this->hasOne(MeetingReport::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    /** Did the organizer pick people explicitly (as opposed to a department-wide meeting)? */
    public function hasExplicitAudience(): bool
    {
        if ($this->relationLoaded('participants')) {
            return $this->participants->contains(fn (MeetingParticipant $p) => $p->source === MeetingParticipant::SOURCE_INVITED);
        }

        return $this->participants()->where('source', MeetingParticipant::SOURCE_INVITED)->exists();
    }
}
