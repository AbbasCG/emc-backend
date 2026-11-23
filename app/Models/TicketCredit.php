<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one and only credit a ticket can ever produce: written in the same transaction as the ledger award and
 * the hours increment, and UNIQUE on ticket_id so the database itself refuses a second credit for a ticket
 * (the row lock + existence check in TicketCreditService is the primary guard, this index the backstop).
 *
 * Append-only by design: nothing in the application updates or deletes a row, and a credit is never reversed
 * (same policy as regular tasks).
 */
class TicketCredit extends Model
{
    protected $fillable = ['ticket_id', 'user_id', 'points', 'hours', 'minutes', 'approved_by_id', 'credited_at'];

    protected $casts = [
        'points' => 'integer',
        'hours' => 'float',
        'minutes' => 'integer',
        'credited_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }
}
