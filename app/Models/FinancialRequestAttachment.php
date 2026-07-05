<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialRequestAttachment extends Model
{
    protected $fillable = [
        'financial_request_id', 'uploaded_by',
        'original_name', 'file_path', 'disk', 'mime_type', 'file_size',
    ];

    public function financialRequest(): BelongsTo
    {
        return $this->belongsTo(FinancialRequest::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
