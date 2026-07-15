<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AmbassadorApplicationFile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'ambassador_application_id',
        'category',
        'original_name',
        'stored_name',
        'mime_type',
        'extension',
        'original_size',
        'optimized_size',
        'compression_ratio',
        'checksum',
        'disk',
        'path',
        'thumbnail_path',
        'preview_path',
        'processing_status',
        'processing_error',
        'uploaded_by',
        'uploaded_at',
        'processed_at',
    ];

    protected $casts = [
        'uploaded_at'      => 'datetime',
        'processed_at'     => 'datetime',
        'original_size'    => 'integer',
        'optimized_size'   => 'integer',
        'compression_ratio' => 'float',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function ambassadorApplication(): BelongsTo
    {
        return $this->belongsTo(AmbassadorApplication::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getFileSizeForHumansAttribute(): string
    {
        $bytes = $this->original_size ?? 0;

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    public function getCategoryLabelAttribute(): string
    {
        return match ($this->category) {
            'cv'          => 'السيرة الذاتية',
            'photo'       => 'الصورة الشخصية',
            'student_id'  => 'بطاقة الطالب',
            'certificate' => 'شهادة',
            default       => 'ملف آخر',
        };
    }
}
