<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiAutomationRule extends Model
{
    protected $fillable = ['name', 'trigger', 'conditions', 'ai_action', 'action_config', 'status', 'created_by'];

    protected $casts = [
        'conditions'    => 'array',
        'action_config' => 'array',
    ];

    public function executions(): HasMany
    {
        return $this->hasMany(AiAutomationExecution::class, 'rule_id')->latest();
    }
}
