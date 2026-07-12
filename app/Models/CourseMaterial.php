<?php

namespace App\Models;

use App\Enums\MaterialType;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseMaterial extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        // Organisational
        'course_id',
        'workshop_id',
        'session_id',
        'module_id',
        'course_session_id',
        // Content
        'title',
        'description',
        'type',
        'sort_order',
        'is_visible',
        // File (legacy compat + secure new fields)
        'file_path',
        'file_url',
        'external_url',
        // Secure metadata
        'original_filename',
        'stored_filename',
        'mime_type',
        'extension',
        'size',
        'uploaded_by',
        'disk',
        'sha256',
        // Stats
        'download_count',
        'last_downloaded_at',
        // Image optimisation
        'thumbnail_path',
        'medium_path',
        'image_optimized',
        // Pipeline
        'processing_status',
        'virus_scanned_at',
        'virus_status',
    ];

    protected $casts = [
        'is_visible'          => 'boolean',
        'image_optimized'     => 'boolean',
        'size'                => 'integer',
        'download_count'      => 'integer',
        'sort_order'          => 'integer',
        'last_downloaded_at'  => 'datetime',
        'virus_scanned_at'    => 'datetime',
        'type'                => MaterialType::class,
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class, 'workshop_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LmsSession::class, 'session_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }

    public function courseSession(): BelongsTo
    {
        return $this->belongsTo(CourseSession::class, 'course_session_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(MaterialAuditLog::class, 'material_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function hasFile(): bool
    {
        return ! empty($this->file_path);
    }

    public function isUrlOnly(): bool
    {
        return $this->type?->isUrlOnly() ?? false;
    }

    public function humanSize(): ?string
    {
        if (! $this->size) {
            return null;
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = $this->size;
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }

    public function isProcessing(): bool
    {
        return $this->processing_status === 'processing';
    }

    public function isVirusClean(): bool
    {
        return $this->virus_status === 'clean' || $this->virus_scanned_at === null;
    }
}
