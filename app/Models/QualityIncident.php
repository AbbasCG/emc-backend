<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityIncident extends Model
{
    protected $fillable = [
        'reporter_id', 'assignee_id', 'title', 'description',
        'severity', 'priority', 'status', 'category',
        'related_type', 'related_id', 'evidence', 'resolution',
        'due_date', 'resolved_at',
    ];

    protected $casts = [
        'due_date'    => 'date',
        'resolved_at' => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
