<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerUser extends Model
{
    protected $fillable = ['partner_id', 'user_id', 'role_title', 'status'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
