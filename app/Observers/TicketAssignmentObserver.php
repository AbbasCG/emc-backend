<?php

namespace App\Observers;

use App\Models\Ticket;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the person a ticket was just handed to — in the notification centre and
 * by email — so they can review it and accept or decline.
 *
 * WHY AN OBSERVER: assigned_to_id is written by approve (TicketController) and by
 * reassign / bulk reassign (TicketActions). Hooking the model keeps the rule in
 * one place and covers any future writer. Authorization stays in those callers,
 * so an unauthorized request never reaches the write and never notifies.
 *
 * WHEN IT FIRES: only on an update where assigned_to_id really changes to a
 * (different) user. Creating a ticket never notifies (submission has no
 * assignee; seeders/imports must stay silent). Re-saving a ticket, changing its
 * priority, or a reassign that keeps the same person on a ticket they have not
 * accepted yet sends nothing; one that sends an IN_PROGRESS (accepted) ticket back
 * to them as ASSIGNED — awaiting their reply again — notifies once. Delivery is
 * deferred with DB::afterCommit, so a rolled-back assignment never notifies, and
 * a failure to notify can never fail the assignment itself.
 *
 * Skipped quietly: an assignee who is gone or inactive. Assigning a ticket to
 * yourself is a real assignment and notifies like any other, through whichever
 * channels the admin and your own preferences allow.
 */
class TicketAssignmentObserver
{
    public function updated(Ticket $ticket): void
    {
        if (! $ticket->assigned_to_id) {
            return;
        }

        $newAssignee = $ticket->wasChanged('assigned_to_id');
        // Same person, but their acceptance is back to "awaiting a reply" (TicketActions::reassign
        // writes IN_PROGRESS -> ASSIGNED and clears accepted_at): they must accept again. Either
        // signal counts, because older IN_PROGRESS rows can carry no accepted_at at all.
        $backFromInProgress = $ticket->wasChanged('status')
            && $ticket->getOriginal('status') === 'IN_PROGRESS';
        $acceptanceCleared = $ticket->wasChanged('accepted_at')
            && $ticket->accepted_at === null
            && $ticket->getOriginal('accepted_at') !== null;
        $mustAcceptAgain = $ticket->status === 'ASSIGNED' && ($backFromInProgress || $acceptanceCleared);

        if (! $newAssignee && ! $mustAcceptAgain) {
            return;
        }

        $previous = $ticket->getOriginal('assigned_to_id');
        $this->queue($ticket, $previous ? (int) $previous : null);
    }

    private function queue(Ticket $ticket, ?int $previousAssigneeId): void
    {
        // Captured now: the model instance may be changed again before commit.
        $ticketId = (int) $ticket->getKey();
        $title = (string) $ticket->title;
        $assigneeId = (int) $ticket->assigned_to_id;
        $actorId = request()?->user()?->id;
        // One assignment event: who held it before + the moment (microseconds) it was written.
        $at = now();
        $ref = ($previousAssigneeId ?? 'none').'@'.$at->format('Uu');

        DB::afterCommit(function () use ($ticketId, $title, $assigneeId, $actorId, $ref, $at) {
            $this->notify($ticketId, $title, $assigneeId, $actorId, $ref, $at);
        });
    }

    private function notify(int $ticketId, string $title, int $assigneeId, ?int $actorId, string $ref, \DateTimeInterface $at): void
    {
        $assignee = User::find($assigneeId);
        if (! $assignee || ! $assignee->is_active) {
            Log::info('Ticket assignment notification skipped: assignee unavailable.', [
                'ticket_id' => $ticketId, 'assignee_id' => $assigneeId,
            ]);

            return;
        }

        try {
            NotificationService::ticketAssigned($assignee->id, $ticketId, $title, $ref, $actorId, $at);
        } catch (Throwable $e) {
            // The assignment has already committed; telling the assignee is best-effort.
            report($e);
        }
    }
}
