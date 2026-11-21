<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells an employee that a report or suggestion was assigned to them, with
 * enough context to triage it straight from the inbox and a single link that
 * opens the ticket in review mode (accept / decline at the top).
 *
 * Carries only what the assignee can already read in the app: number, title,
 * type, priority, department/unit, deadline, who assigned it and a short
 * excerpt of the description. Internal notes, attachments and the reporter's
 * contact details are never passed in. The link grants nothing by itself — the
 * details page still authenticates and authorizes the viewer.
 */
class TicketAssignedMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $itemTitle,
        public readonly ?string $ticketNumber,
        public readonly bool $isSuggestion,
        public readonly ?string $priorityLabel,
        /** LOW | MEDIUM | HIGH | CRITICAL — drives the badge colour only. */
        public readonly ?string $priority,
        public readonly ?string $departmentName,
        public readonly ?string $unitName,
        /** Already formatted for display, in the app timezone. */
        public readonly ?string $deadline,
        public readonly ?string $assignedByName,
        public readonly ?string $assignedAt,
        public readonly ?string $excerpt,
        public readonly string $reviewUrl,
        public readonly string $workspaceUrl,
    ) {}

    public function subjectLine(): string
    {
        $prefix = in_array($this->priority, ['HIGH', 'CRITICAL'], true) && $this->priorityLabel
            ? "[أولوية {$this->priorityLabel}] "
            : '';
        $ref = $this->ticketNumber ? " ({$this->ticketNumber})" : '';

        return "{$prefix}أُسندت إليك تذكرة: {$this->itemTitle}{$ref}";
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ticket-assigned');
    }
}
