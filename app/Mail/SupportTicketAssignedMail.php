<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a staff member that a support ticket was assigned to them, with one
 * button that opens it in the support desk.
 *
 * Carries the number, subject, priority, who assigned it and when — never the
 * customer's message, email or phone (the desk shows those after sign-in).
 */
class SupportTicketAssignedMail extends Mailable
{
    use SerializesModels;

    public const PRIORITY_LABELS = ['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية', 'urgent' => 'عاجلة'];

    public function __construct(
        public readonly string $recipientName,
        public readonly string $subjectText,
        public readonly string $ticketNumber,
        /** low | medium | high | urgent */
        public readonly ?string $priority,
        public readonly ?string $assignedByName,
        /** Already formatted for display, in the app timezone. */
        public readonly ?string $assignedAt,
        public readonly string $reviewUrl,
    ) {}

    public function priorityLabel(): ?string
    {
        return self::PRIORITY_LABELS[$this->priority] ?? null;
    }

    public function subjectLine(): string
    {
        $prefix = in_array($this->priority, ['high', 'urgent'], true) ? "[أولوية {$this->priorityLabel()}] " : '';

        // The customer wrote the ticket subject, so it stays out of the email subject line
        // (inbox previews, forwarding rules); the body shows it to the assignee.
        return "{$prefix}تم تعيين تذكرة دعم إليك ({$this->ticketNumber})";
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(view: 'emails.support-ticket-assigned', with: ['priorityLabel' => $this->priorityLabel()]);
    }
}
