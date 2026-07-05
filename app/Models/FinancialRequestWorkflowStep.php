<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialRequestWorkflowStep extends Model
{
    protected $fillable = [
        'financial_request_id', 'actor_id', 'actor_role',
        'action', 'from_status', 'to_status', 'note',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function financialRequest(): BelongsTo
    {
        return $this->belongsTo(FinancialRequest::class);
    }
}
