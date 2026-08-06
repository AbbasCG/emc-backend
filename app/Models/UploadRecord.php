<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'context',
        'file_hash',
        'original_filename',
        'original_size',
        'optimized_size',
        'mime_type',
        'disk',
        'path',
        'thumbnail_path',
        'url',
        'thumbnail_url',
        'status',
        'job_id',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'original_size'  => 'integer',
        'optimized_size' => 'integer',
        'metadata'       => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getSavedPercentAttribute(): int
    {
        if (! $this->original_size || ! $this->optimized_size) {
            return 0;
        }

        return max(0, (int) round((1 - $this->optimized_size / $this->original_size) * 100));
    }
}
