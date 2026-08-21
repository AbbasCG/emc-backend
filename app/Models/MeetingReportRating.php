<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingReportRating extends Model
{
    protected $fillable = ['meeting_report_id', 'user_id', 'attended', 'rating', 'note'];

    protected $casts = [
        'attended' => 'boolean',
        'rating'   => 'integer',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(MeetingReport::class, 'meeting_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
