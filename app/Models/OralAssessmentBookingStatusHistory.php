<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OralAssessmentBookingStatusHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'oral_assessment_booking_id',
        'changed_by',
        'from_status',
        'to_status',
        'reason',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(OralAssessmentBooking::class, 'oral_assessment_booking_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
