<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Document extends Model
{
    protected $fillable = [
        'folder_id', 'department_id', 'uploaded_by', 'title', 'file_path',
        'file_name', 'file_type', 'file_size', 'visibility',
        'related_type', 'related_id', 'tags',
    ];

    protected $casts = ['tags' => 'array'];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function related(): MorphTo
    {
        return $this->morphTo('related');
    }
}
