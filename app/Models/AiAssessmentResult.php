<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiAssessmentResult extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'visitor_id', 'level_id', 'level_title', 'overall', 'scores',
        'goal', 'field', 'name', 'email', 'whatsapp',
        'reward_code', 'reward_expires_at', 'ref', 'created_at',
    ];

    protected $casts = [
        'scores'            => 'array',
        'overall'           => 'integer',
        'reward_expires_at' => 'datetime',
        'created_at'        => 'datetime',
    ];
}
