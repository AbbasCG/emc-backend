<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkshopRequestWorkflowStep extends Model
{
    protected $fillable = [
        'workshop_request_id',
        'step_number',
        'department_key',
        'department_label',
        'status',
        'actor_id',
        'acted_at',
        'notes',
    ];

    protected $casts = [
        'acted_at'    => 'datetime',
        'step_number' => 'integer',
    ];

    public function workshopRequest(): BelongsTo
    {
        return $this->belongsTo(WorkshopRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
