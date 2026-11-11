<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskEvaluation extends Model
{
    protected $fillable = [
        'task_id',
        'evaluator_id',
        'delivered_on_time',
        'quality_of_outputs',
        'autonomy',
        'responsiveness',
        'collaboration',
        'collaboration_na',
        'final_score',
        'feedback',
    ];

    protected $casts = [
        'delivered_on_time'  => 'integer',
        'quality_of_outputs' => 'integer',
        'autonomy'           => 'integer',
        'responsiveness'     => 'integer',
        'collaboration'      => 'integer',
        'collaboration_na'   => 'boolean',
        'final_score'        => 'float',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }
}

