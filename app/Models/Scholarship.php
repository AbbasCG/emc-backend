<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Scholarship extends Model
{
    protected $fillable = [
        'user_id', 'name', 'email', 'type', 'discount_percentage',
        'amount', 'reason', 'status', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'approved_at'         => 'datetime',
        'discount_percentage' => 'decimal:2',
        'amount'              => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
