<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The public-safe, read-only projection of a ticket for the person who
 * submitted it.
 *
 * Why a whitelist and not a blacklist: the staff payload is the whole Eloquent
 * model (internal notes, submitter email, assignee/approver user rows, SLA
 * deadline, the raw activity log). A submitter who follows the "your report was
 * resolved" link must never receive any of that, so this class builds the
 * response from scratch and only ever copies fields that are explicitly listed
 * here. A new column added to `tickets` later is therefore hidden from
 * reporters by default.
 *
 * Authorization is NOT decided here. TicketController::show() has already run
 * canView() and decided this viewer is a reporter-only viewer.
 */
class TicketReporterView
{
    /** Statuses after which the ticket is closed to further follow-up. */
    public const FINAL_STATUSES = ['RESOLVED', 'REJECTED_BY_ADMIN', 'UNRESOLVED'];

    /**
     * @return array<string, mixed>
     */
    public function present(Ticket $ticket, User $viewer): array
    {
        $ticket->loadMissing(['department', 'attachments', 'activityLogs']);

        $isFinal = in_array($ticket->status, self::FINAL_STATUSES, true);

        return [
            'viewer_role'     => 'reporter',
            'id'              => $ticket->id,
            'ticket_number'   => $ticket->ticket_number,
            'title'           => $ticket->title,
            'description'     => $ticket->description,
            'ticket_category' => $ticket->ticket_category,
            'status'          => $ticket->status,
            'created_at'      => $ticket->created_at?->toIso8601String(),
            'updated_at'      => $ticket->updated_at?->toIso8601String(),
            'resolved_at'     => $ticket->resolved_at?->toIso8601String(),

            // The assignee's written answer. Only meaningful once resolved.
            'resolution_note' => $ticket->status === 'RESOLVED' ? $this->clean($ticket->resolution_summary) : null,

            // The department head writes this reason for the submitter (the
            // reject dialog says so); the assignee's apology reason and the
            // "unresolved" escalation reason are internal and never leave.
            'rejection_reason' => $ticket->status === 'REJECTED_BY_ADMIN' ? $this->clean($ticket->admin_rejection_reason) : null,

            'department' => $ticket->department
                ? ['id' => $ticket->department->id, 'name' => $ticket->department->name_ar ?: $ticket->department->name]
                : null,

            'attachments' => $this->attachments($ticket),
            'timeline'    => $this->timeline($ticket, $viewer),

            // Every workflow action is off for a reporter. `comment` mirrors
            // whether the follow-up box is shown (open tickets only).
            'capabilities' => [
                'approve'           => false,
                'reject'            => false,
                'reassign'          => false,
                'accept_task'       => false,
                'reject_task'       => false,
                'complete_task'     => false,
                'add_internal_note' => false,
                'comment'           => ! $isFinal,
            ],
        ];
    }

    /**
     * One row of the ticket list for a submitter. A whitelist, like present(): no assignee, approver,
     * deadline, internal notes, submitter email or rejection reasons, and every action off. A column
     * added to `tickets` later is hidden from reporters by default.
     *
     * @return array<string, mixed>
     */
    public function row(Ticket $ticket): array
    {
        $ticket->loadMissing('department');

        return [
            'viewer_role'     => 'reporter',
            'id'              => $ticket->id,
            'ticket_number'   => $ticket->ticket_number,
            'title'           => $ticket->title,
            'ticket_category' => $ticket->ticket_category,
            'priority'        => $ticket->priority,
            'status'          => $ticket->status,
            'created_at'      => $ticket->created_at?->toIso8601String(),
            'updated_at'      => $ticket->updated_at?->toIso8601String(),
            'resolved_at'     => $ticket->resolved_at?->toIso8601String(),
            'department_id'   => $ticket->department_id,
            'department'      => $ticket->department ? ['id' => $ticket->department->id, 'name' => $ticket->department->name_ar ?: $ticket->department->name] : null,
            'capabilities'    => [
                'approve' => false, 'reject' => false, 'reassign' => false, 'accept_task' => false, 'reject_task' => false,
                'complete_task' => false, 'add_internal_note' => false, 'comment' => ! in_array($ticket->status, self::FINAL_STATUSES, true), 'change_priority' => false, 'can_edit_estimate' => false,
            ],
        ];
    }

    /**
     * Only what the reporter themself uploaded at submission. Proof files an
     * assignee attached while resolving are working material, not part of the
     * reporter's own submission.
     *
     * @return array<int, array<string, mixed>>
     */
    private function attachments(Ticket $ticket): array
    {
        return $ticket->attachments
            ->filter(fn (TicketAttachment $a) => $a->attachment_context === 'INITIAL_SUBMISSION')
            ->map(fn (TicketAttachment $a) => [
                'id'                 => $a->id,
                'ticket_id'          => $a->ticket_id,
                'preview_url'        => $a->preview_url,
                'file_name'          => $a->file_name,
                'file_type'          => $a->file_type,
                'mime_type'          => $a->mime_type,
                'file_size'          => $a->file_size,
                'is_image'           => $a->is_image,
                'attachment_context' => $a->attachment_context,
                'created_at'         => $a->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * A short, plain-language history. Built from the activity log but never
     * echoing a log row's free-text `details` (those carry SLA deadlines,
     * assignee names, reassignment reasons and internal notes). The submitter's
     * own follow-up comments are the one exception: they wrote that text.
     *
     * @return array<int, array{key: string, label: string, at: string|null, note?: string}>
     */
    private function timeline(Ticket $ticket, User $viewer): array
    {
        $isSuggestion = $ticket->ticket_category === 'NEW_SUGGESTION';
        $noun = $isSuggestion ? 'المقترح' : 'البلاغ';

        /** @var Collection<int, \App\Models\TicketActivityLog> $logs */
        $logs = $ticket->activityLogs->sortBy('logged_at')->values();

        $steps = [];
        $seen = [];
        $push = function (string $key, string $label, $at, ?string $note = null) use (&$steps, &$seen) {
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $step = ['key' => $key, 'label' => $label, 'at' => $at?->toIso8601String()];
            if ($note !== null && $note !== '') {
                $step['note'] = $note;
            }
            $steps[] = $step;
        };

        // Always present, even if the log row is missing on a legacy ticket.
        $push('submitted', "تم تقديم {$noun}", $ticket->created_at);

        foreach ($logs as $log) {
            switch ($log->action) {
                case 'APPROVED_AND_ASSIGNED':
                    $push('approved', $isSuggestion ? 'تم اعتماد المقترح' : 'تمت مراجعة البلاغ وإحالته للفريق المختص', $log->logged_at);
                    break;
                case 'ACCEPTED_BY_ASSIGNEE':
                    $push('in_progress', 'بدأت المعالجة', $log->logged_at);
                    break;
                case 'REJECTED_BY_ADMIN':
                    $push('declined', "تعذّر قبول {$noun}", $log->logged_at, $this->clean($ticket->admin_rejection_reason));
                    break;
                case 'MARKED_UNRESOLVED':
                    $push('escalated', "{$noun} قيد المراجعة من الإدارة", $log->logged_at);
                    break;
                case 'RESOLVED':
                    $push('resolved', $isSuggestion ? 'تم تنفيذ المقترح' : 'تم حل البلاغ', $log->logged_at);
                    break;
                case 'COMMENT':
                    if ((int) $log->performed_by === (int) $viewer->id) {
                        $steps[] = [
                            'key'   => 'comment-' . $log->id,
                            'label' => 'أضفتَ تعقيباً',
                            'at'    => $log->logged_at?->toIso8601String(),
                            'note'  => $this->clean($log->details),
                        ];
                    }
                    break;
                // SUBMITTED is covered above; REASSIGNED, REJECTED_BY_ASSIGNEE,
                // INTERNAL_NOTE and any other system action are deliberately not shown.
            }
        }

        // A ticket resolved without a log row (e.g. a data fix) still ends in a resolved step.
        if ($ticket->status === 'RESOLVED') {
            $push('resolved', $isSuggestion ? 'تم تنفيذ المقترح' : 'تم حل البلاغ', $ticket->resolved_at ?? $ticket->updated_at);
        }

        return $steps;
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
