<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerProgram extends Model
{
    protected $fillable = [
        'partner_id', 'course_id', 'track_id', 'title', 'description',
        'status', 'start_date', 'end_date', 'participant_count', 'metadata',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'metadata'   => 'array',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
