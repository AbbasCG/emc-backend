<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnershipRequest extends Model
{
    protected $fillable = [
        'partner_name', 'contact_name', 'email', 'phone',
        'country', 'city', 'website',
        'type', 'type_other', 'partnership_type', 'partnership_type_other',
        'message', 'privacy_accepted', 'status', 'reviewed_by',
    ];

    protected $casts = [
        'privacy_accepted' => 'boolean',
    ];

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
