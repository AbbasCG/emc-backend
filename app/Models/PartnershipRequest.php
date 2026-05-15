<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnershipRequest extends Model
{
    protected $fillable = [
        'partner_name', 'contact_name', 'email', 'phone',
        'type', 'message', 'status', 'reviewed_by',
    ];

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
