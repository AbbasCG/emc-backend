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
        'attachment_context',
        'uploaded_by',
    ];

    /**
     * file_url is the raw private-disk storage path — never expose it
     * directly in API responses. Serialize the authenticated, authorization-
     * checked preview route instead (see appends below).
     */
    protected $hidden = ['file_url'];

    protected $appends = ['preview_url'];

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
