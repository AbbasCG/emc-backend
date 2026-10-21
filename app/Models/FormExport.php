<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to export the responses of a form. The file lives on the PRIVATE disk, is downloadable only by the person who
 * asked (and only while they may still read the responses), and is deleted when it expires.
 */
class FormExport extends Model
{
    public const XLSX = 'xlsx';

    public const CSV = 'csv';

    public const PDF = 'pdf';

    public const FORMATS = [self::XLSX, self::CSV, self::PDF];

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    /** How long a finished file stays available. */
    public const TTL_HOURS = 24;

    protected $fillable = ['form_id', 'user_id', 'format', 'status', 'filters', 'columns', 'row_count', 'file_name', 'file_path', 'file_size', 'error', 'completed_at', 'expires_at'];

    protected $casts = [
        'filters'      => 'array',
        'columns'      => 'array',
        'completed_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(DynamicForm::class, 'form_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::READY && $this->file_path !== null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
