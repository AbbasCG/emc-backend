<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmbassadorApplicationStatusHistory extends Model
{
    protected $table = 'ambassador_application_status_histories';

    public $timestamps = true;

    protected $fillable = [
        'ambassador_application_id',
        'changed_by',
        'from_status',
        'to_status',
        'reason',
        'changed_at',
    ];

    protected $casts = ['changed_at' => 'datetime'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(AmbassadorApplication::class, 'ambassador_application_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
