<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MarketingItem extends Model
{
    protected $fillable = [
        'title', 'description', 'platform', 'status', 'responsible_user_id',
        'related_type', 'related_id', 'publish_date', 'content_text', 'assets',
    ];

    protected $casts = [
        'assets'       => 'array',
        'publish_date' => 'date',
    ];

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo('related');
    }
}
