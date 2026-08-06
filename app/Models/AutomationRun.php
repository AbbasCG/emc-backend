<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRun extends Model
{
    protected $fillable = ['rule_id', 'status', 'payload', 'result', 'executed_at'];

    protected $casts = [
        'payload'     => 'array',
        'result'      => 'array',
        'executed_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }
}
