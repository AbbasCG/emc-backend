<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityCorrectiveAction extends Model
{
    protected $fillable = [
        'created_by', 'assignee_id', 'quality_incident_id', 'quality_review_id',
        'title', 'description', 'priority', 'status', 'progress',
        'due_date', 'completed_at', 'notes',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'completed_at' => 'datetime',
        'progress'     => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(QualityIncident::class, 'quality_incident_id');
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(QualityReview::class, 'quality_review_id');
    }
}
