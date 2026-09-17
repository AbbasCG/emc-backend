<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'file_url',
        'file_name',
        'file_type',
        'mime_type',
        'file_size',
        'attachment_context',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    /**
     * file_url is the raw private-disk storage path — never expose it
     * directly in API responses. Serialize the authenticated, authorization-
     * checked preview route instead (see appends below).
     */
    protected $hidden = ['file_url'];

    protected $appends = ['preview_url', 'is_image'];

    /**
     * Should the UI fetch this inline as an image?
     *
     * mime_type is NULL for rows created before it was recorded, so fall back
     * to the coarse file_type the row has always carried. Nothing here decides
     * authorization - it only decides how the client renders the attachment.
     */
    public function getIsImageAttribute(): bool
    {
        if (is_string($this->mime_type) && $this->mime_type !== '') {
            return str_starts_with($this->mime_type, 'image/');
        }

        return $this->file_type === 'IMAGE';
    }

    public function getPreviewUrlAttribute(): ?string
    {
        if (! $this->ticket_id || ! $this->id) {
            return null;
        }

        return route('tickets.attachments.download', [
            'ticketId' => $this->ticket_id,
            'attachmentId' => $this->id,
        ]);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
