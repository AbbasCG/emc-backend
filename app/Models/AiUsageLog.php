<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'model', 'action',
        'tokens_input', 'tokens_output', 'estimated_cost', 'status',
    ];

    protected $casts = ['estimated_cost' => 'float'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
