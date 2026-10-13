<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A volunteer opportunity. In this phase it only ever exists as a DRAFT created from an HR
 * incoming request: nothing publishes it and nothing public reads it. `status` and
 * `published_at` are deliberately not mass-assignable so no request payload can flip them.
 */
class VolunteerOpportunity extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const LOCATION_MODES = ['remote', 'onsite', 'hybrid'];

    protected $fillable = [
        'incoming_request_id', 'department_id', 'title', 'summary', 'responsibilities', 'required_skills', 'preferred_skills',
        'volunteers_needed', 'availability', 'location_mode', 'start_date', 'application_instructions', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'responsibilities' => 'array',
        'required_skills' => 'array',
        'preferred_skills' => 'array',
        'volunteers_needed' => 'integer',
        'start_date' => 'date',
        'published_at' => 'datetime',
    ];

    public function incomingRequest(): BelongsTo
    {
        return $this->belongsTo(HrRequest::class, 'incoming_request_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT && $this->published_at === null;
    }
}
