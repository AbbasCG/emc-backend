<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the person who submitted a report or suggestion that its status
 * changed in a way that matters to them (resolved / approved / implemented).
 *
 * Deliberately carries ONLY what the submitter may already see in the app: the
 * title, the new status and a link. Internal notes, resolution summaries and
 * the handler's identity are never passed in, so they cannot leak by email. The
 * link does not grant access — the details page still authorizes the viewer.
 */
class TicketStatusUpdateMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        /** ticket_resolved | suggestion_approved | suggestion_implemented */
        public readonly string $kind,
        public readonly string $itemTitle,
        public readonly string $statusLabel,
        public readonly string $headline,
        public readonly string $detailsUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->headline}: {$this->itemTitle}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ticket-status-update');
    }
}
