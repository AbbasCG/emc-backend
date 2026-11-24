<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketActivityLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'action',
        'previous_state',
        'new_state',
        'performed_by',
        'performed_by_name',
        'details',
        'metadata',
        'logged_at',
    ];

    protected $casts = [
        'logged_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Structured facts about a row (today: the reviewer's relation to the ticket on the review rows). Hidden in every
     * serialization; TicketAccess::redactInternal makes it visible for the people who may read internal rows.
     */
    protected $hidden = ['metadata'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
