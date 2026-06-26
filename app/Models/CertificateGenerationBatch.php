<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CertificateGenerationBatch extends Model
{
    protected $fillable = [
        'batch_code', 'certificate_type', 'related_type', 'related_id',
        'template_id', 'status', 'total_recipients', 'generated_count',
        'failed_count', 'created_by', 'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $batch) {
            if (empty($batch->batch_code)) {
                $batch->batch_code = 'BATCH-' . date('Ymd') . '-' . strtoupper(Str::random(6));
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

    public function certificates()
    {
        return $this->hasMany(Certificate::class, 'batch_id');
    }
}
