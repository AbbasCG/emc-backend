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
        'converted_partner_id', 'converted_user_id', 'review_notes', 'reviewed_at',
    ];

    protected $casts = [
        'privacy_accepted' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function convertedPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'converted_partner_id');
    }

    public function convertedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_user_id');
    }
}
