<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Report extends Model
{
    protected $fillable = [
        'title', 'type', 'reportable_type', 'reportable_id',
        'generated_by', 'data', 'summary', 'status', 'generated_at',
    ];

    protected $casts = [
        'data'         => 'array',
        'generated_at' => 'datetime',
    ];

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }
}
