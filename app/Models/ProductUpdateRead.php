<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUpdateRead extends Model
{
    protected $fillable = ['product_update_id', 'user_id', 'read_at'];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function productUpdate(): BelongsTo
    {
        return $this->belongsTo(ProductUpdate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
