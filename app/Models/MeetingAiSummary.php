<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingAiSummary extends Model
{
    protected $fillable = [
        'meeting_id', 'summary', 'decisions', 'action_items', 'risks', 'blockers', 'model', 'token_usage',
    ];

    protected $casts = [
        'decisions'    => 'array',
        'action_items' => 'array',
        'risks'        => 'array',
        'blockers'     => 'array',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
