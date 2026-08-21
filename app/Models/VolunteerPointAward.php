<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VolunteerPointAward extends Model
{
    /** فئات المنح — تعكس أبواب سياسة نقاط أثر EMC v1.0. */
    public const CATEGORIES = [
        'meetings', 'task', 'quality_bonus', 'initiative', 'workshop', 'camp',
        'community', 'mentoring', 'knowledge', 'institutional',
        'best_of_department', 'best_of_center', 'exceptional_impact',
        'continuity', 'other',
    ];

    protected $fillable = [
        'user_id', 'category', 'points', 'reason',
        'awarded_by', 'source_type', 'source_id', 'awarded_at',
    ];

    protected $casts = [
        'points'     => 'integer',
        'awarded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function awarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }
}
