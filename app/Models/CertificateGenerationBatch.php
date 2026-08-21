<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CertificateGenerationBatch extends Model
{
    // ── Status constants ──────────────────────────────────────────────────────
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    protected $fillable = [
        'batch_code', 'certificate_type', 'related_type', 'related_id',
        'template_id', 'status', 'total_recipients', 'generated_count',
        'failed_count', 'created_by', 'notes', 'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $batch) {
            if (empty($batch->batch_code)) {
                $batch->batch_code = 'BATCH-' . date('Ymd') . '-' . strtoupper(Str::random(6));
            }
            if (empty($batch->certificate_type)) {
                $batch->certificate_type = 'course_completion';
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CertificateTemplate::class, 'template_id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'batch_id');
    }
}
