<?php

namespace App\Observers;

use App\Models\Ticket;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells a ticket's submitter when their report is resolved or their suggestion
 * is approved / implemented.
 *
 * WHY AN OBSERVER: ticket status is written from several places (approve,
 * accept, complete, reassign, …). Hooking the model means the rule lives once
 * and also covers any future writer, instead of each controller method
 * remembering to notify. Authorization stays where it always was — in the
 * controller — so an unauthorized request never reaches the update and
 * therefore never produces a notification.
 *
 * WHEN IT FIRES: only on a real transition into the target state. `updated`
 * runs for every save, so the rule compares the ORIGINAL status with the new
 * one; re-saving a ticket that is already RESOLVED (or editing an approved
 * suggestion) changes no status and sends nothing. Delivery is deferred with
 * DB::afterCommit, so a rolled-back status change never notifies and the queue
 * workers never see a half-written ticket. A failure to notify can never undo
 * or fail the status change itself.
 *
 *   report     (OLD_ISSUE)      any state  -> RESOLVED            => ticket_resolved
 *   suggestion (NEW_SUGGESTION) PENDING_APPROVAL -> ASSIGNED      => suggestion_approved
 *   suggestion (NEW_SUGGESTION) any state  -> RESOLVED            => suggestion_implemented
 *
 * For suggestions, ASSIGNED reached from PENDING_APPROVAL is the management
 * approval (TicketController::approve); RESOLVED is "completed / added to the
 * system". UNRESOLVED and rejections are intentionally not announced here.
 *
 * RESOLVED is reached by the FINAL APPROVAL of the completion
 * (TicketCreditService, PENDING_REVIEW -> RESOLVED), not by the assignee's
 * «تم الحل» (that only moves IN_PROGRESS -> PENDING_REVIEW, an internal step
 * that announces nothing). So the submitter hears "resolved" exactly when the
 * work is finally approved. The only other way to RESOLVED is a legacy
 * IN_PROGRESS ticket with no assignee, closed directly as before.
 */
class TicketStatusObserver
{
    public function updated(Ticket $ticket): void
    {
        if (! $ticket->wasChanged('status')) {
            return;
        }

        $kind = $this->transitionKind(
            (string) $ticket->ticket_category,
            (string) $ticket->getOriginal('status'),
            (string) $ticket->status,
        );

        if ($kind === null) {
            return;
        }

        // Captured now: the model instance may be changed again before commit.
        $ticketId = (int) $ticket->getKey();
        $title = (string) $ticket->title;
        $submitterId = $ticket->created_by_id ? (int) $ticket->created_by_id : null;
        $actorId = request()?->user()?->id;

        DB::afterCommit(function () use ($kind, $ticketId, $title, $submitterId, $actorId) {
            $this->notify($kind, $ticketId, $title, $submitterId, $actorId);
        });
    }

    private function transitionKind(string $category, string $from, string $to): ?string
    {
        $isSuggestion = $category === 'NEW_SUGGESTION';

        if ($to === 'RESOLVED' && $from !== 'RESOLVED') {
            return $isSuggestion ? 'suggestion_implemented' : 'ticket_resolved';
        }

        if ($isSuggestion && $from === 'PENDING_APPROVAL' && $to === 'ASSIGNED') {
            return 'suggestion_approved';
        }

        return null;
    }

    private function notify(string $kind, int $ticketId, string $title, ?int $submitterId, ?int $actorId): void
    {
        // User::find excludes soft-deleted accounts. An anonymous / legacy ticket
        // with no submitter, or a submitter who is gone or deactivated, is
        // skipped quietly — nobody to tell, and never an error.
        $submitter = $submitterId ? User::find($submitterId) : null;

        if (! $submitter || ! $submitter->is_active) {
            Log::info('Ticket status notification skipped: submitter unavailable.', [
                'kind' => $kind, 'ticket_id' => $ticketId, 'submitter_id' => $submitterId,
            ]);

            return;
        }

        // The submitter acted on their own ticket — they already know.
        if ($actorId !== null && $actorId === $submitter->id) {
            return;
        }

        try {
            match ($kind) {
                'ticket_resolved'        => NotificationService::ticketResolved($submitter->id, $ticketId, $title),
                'suggestion_approved'    => NotificationService::suggestionApproved($submitter->id, $ticketId, $title),
                'suggestion_implemented' => NotificationService::suggestionImplemented($submitter->id, $ticketId, $title),
            };
        } catch (Throwable $e) {
            // The status change has already committed; telling the user is
            // best-effort and must never surface as a failed request.
            report($e);
        }
    }
}
