<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAutomationExecution extends Model
{
    protected $fillable = ['rule_id', 'status', 'input', 'output', 'error_message', 'started_at', 'finished_at'];

    protected $casts = [
        'input'       => 'array',
        'output'      => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AiAutomationRule::class);
    }
}
