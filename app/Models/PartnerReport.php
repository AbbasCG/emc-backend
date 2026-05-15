<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerReport extends Model
{
    protected $fillable = ['partner_id', 'created_by', 'title', 'summary', 'data', 'period', 'status'];

    protected $casts = ['data' => 'array'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
