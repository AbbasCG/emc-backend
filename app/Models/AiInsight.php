<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiInsight extends Model
{
    protected $fillable = [
        'type', 'entity_type', 'entity_id', 'title', 'summary',
        'score', 'severity', 'metadata', 'generated_at',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'generated_at' => 'datetime',
        'score'        => 'float',
    ];
}
