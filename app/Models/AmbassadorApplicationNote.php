<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmbassadorApplicationNote extends Model
{
    protected $fillable = ['ambassador_application_id', 'created_by', 'content', 'is_private'];

    protected $casts = ['is_private' => 'boolean'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(AmbassadorApplication::class, 'ambassador_application_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
