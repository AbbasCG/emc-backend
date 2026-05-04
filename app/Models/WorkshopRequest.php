<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkshopRequest extends Model
{
    protected $fillable = [
        'requester_name',
        'requester_email',
        'requester_phone',
        'requester_department',
        'program_name',
        'speaker_name',
        'speaker_job_title',
        'speaker_photo',
        'topics',
        'categories',
        'target_audience',
        'proposed_date',
        'proposed_time',
        'location_types',
        'price_type',
        'price_amount',
        'generated_announcement_text',
        'status',
    ];

    protected $casts = [
        'categories' => 'array',
        'location_types' => 'array',
        'proposed_date' => 'date',
        'price_amount' => 'decimal:2',
    ];
}
