<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class QualityReview extends Model
{
    protected $fillable = [
        'reviewable_type', 'reviewable_id', 'reviewer_id',
        'overall_score', 'objective_clarity_score', 'content_quality_score',
        'instructor_score', 'organization_score', 'time_commitment_score',
        'completion_score', 'output_quality_score', 'repeatability_score',
        'notes', 'recommendations', 'status', 'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at'              => 'datetime',
        'overall_score'            => 'decimal:2',
        'objective_clarity_score'  => 'decimal:2',
        'content_quality_score'    => 'decimal:2',
        'instructor_score'         => 'decimal:2',
        'organization_score'       => 'decimal:2',
        'time_commitment_score'    => 'decimal:2',
        'completion_score'         => 'decimal:2',
        'output_quality_score'     => 'decimal:2',
        'repeatability_score'      => 'decimal:2',
    ];

    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
