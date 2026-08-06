<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends Model
{
    protected $fillable = ['name', 'trigger_type', 'conditions', 'actions', 'status', 'created_by'];

    protected $casts = [
        'conditions' => 'array',
        'actions'    => 'array',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'rule_id')->latest();
    }
}
