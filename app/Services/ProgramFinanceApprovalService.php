<?php

namespace App\Services;

use App\Models\PlatformNotification;
use App\Services\AuditLogService;
use App\Models\ProgramFinanceApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ProgramFinanceApprovalService
{
    /**
     * Submit a paid program for finance review.
     * Creates or resets the approval record, updates the denormalized status column.
     */
    public function submit(Model $program, User $actor): ProgramFinanceApproval
    {
        return DB::transaction(function () use ($program, $actor) {
            $approval = ProgramFinanceApproval::updateOrCreate(
                [
                    'approvable_type' => get_class($program),
                    'approvable_id'   => $program->id,
                ],
                [
                    'status'           => 'pending',
                    'submitted_by'     => $actor->id,
                    'submitted_at'     => now(),
                    'reviewed_by'      => null,
                    'reviewed_at'      => null,
                    'review_note'      => null,
                    'rejection_reason' => null,
                    'price_snapshot'   => (float) $program->price,
                    'currency_snapshot' => strtoupper($program->currency ?? 'EUR'),
                ],
            );

            // Denormalize onto the program row
            $program->update([
                'finance_approval_status'  => 'pending',
                'submitted_for_finance_at' => now(),
            ]);

            AuditLogService::log(
                'PROGRAM_SUBMITTED_FOR_FINANCE',
                class_basename($program),
                $program->id,
                $program->title,
                ['finance_approval_status' => 'pending', 'price' => $program->price],
                null,
                null,
            );

            // Notify all finance managers
            $this->notifyFinanceManagers($program, $actor, $approval);

            return $approval;
        });
    }

    /**
     * Finance manager approves the program.
     */
    public function approve(Model $program, User $reviewer, ?string $note = null): ProgramFinanceApproval
    {
        return DB::transaction(function () use ($program, $reviewer, $note) {
            $approval = ProgramFinanceApproval::where('approvable_type', get_class($program))
                ->where('approvable_id', $program->id)
                ->firstOrFail();

            $approval->update([
                'status'      => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $program->update(['finance_approval_status' => 'approved']);

            AuditLogService::log(
                'PROGRAM_FINANCE_APPROVED',
                class_basename($program),
                $program->id,
                $program->title,
                ['finance_approval_status' => 'approved', 'reviewed_by' => $reviewer->name, 'note' => $note],
                null,
                null,
            );

            // Notify creator and admins
            $this->notifyApproval($program, $reviewer, $approval);

            return $approval;
        });
    }

    /**
     * Finance manager rejects the program.
     */
    public function reject(Model $program, User $reviewer, string $reason, ?string $note = null): ProgramFinanceApproval
    {
        return DB::transaction(function () use ($program, $reviewer, $reason, $note) {
            $approval = ProgramFinanceApproval::where('approvable_type', get_class($program))
                ->where('approvable_id', $program->id)
                ->firstOrFail();

            $approval->update([
                'status'           => 'rejected',
                'reviewed_by'      => $reviewer->id,
                'reviewed_at'      => now(),
                'review_note'      => $note,
                'rejection_reason' => $reason,
            ]);

            $program->update(['finance_approval_status' => 'rejected']);

            AuditLogService::log(
                'PROGRAM_FINANCE_REJECTED',
                class_basename($program),
                $program->id,
                $program->title,
                ['finance_approval_status' => 'rejected', 'reason' => $reason, 'reviewed_by' => $reviewer->name],
                null,
                null,
            );

            // Notify creator
            $this->notifyRejection($program, $reviewer, $approval);

            return $approval;
        });
    }

    /**
     * When price/currency changes on an already-approved paid program, reset to pending.
     */
    public function resetOnPriceChange(Model $program): void
    {
        if (! $program->isPaid()) {
            return;
        }
        if (($program->finance_approval_status ?? 'not_required') !== 'approved') {
            return;
        }

        ProgramFinanceApproval::where('approvable_type', get_class($program))
            ->where('approvable_id', $program->id)
            ->update([
                'status'      => 'pending',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_note' => null,
                'rejection_reason' => null,
                'price_snapshot' => (float) $program->price,
            ]);

        $program->update(['finance_approval_status' => 'pending']);

        AuditLogService::log(
            'PROGRAM_PRICE_CHANGED',
            class_basename($program),
            $program->id,
            $program->title,
            ['finance_approval_status' => 'pending', 'reason' => 'price_changed_after_approval'],
            null,
            null,
        );
    }

    // ─── Notification helpers ─────────────────────────────────────────────────

    private function notifyFinanceManagers(Model $program, User $actor, ProgramFinanceApproval $approval): void
    {
        $now      = now();
        $type     = class_basename($program);
        $price    = number_format((float) $program->price, 2);
        $currency = strtoupper($program->currency ?? 'EUR');

        // Deduplicate by user id so a user who is both admin and super_admin isn't notified twice
        $notifiedIds = collect();
        $rows        = collect();

        // Finance managers — link to their approvals page
        $managers = User::where('is_active', true)
            ->where('role', 'finance_manager')
            ->select('id')
            ->get();

        foreach ($managers as $u) {
            if ($notifiedIds->contains($u->id)) {
                continue;
            }
            $notifiedIds->push($u->id);
            $rows->push([
                'user_id'     => $u->id,
                'type'        => 'finance_approval_request',
                'title'       => 'برنامج مدفوع جديد بانتظار المراجعة المالية',
                'message'     => "تم إرسال البرنامج «{$program->title}» للمراجعة المالية بقيمة {$price} {$currency}.",
                'entity_type' => $type,
                'entity_id'   => $program->id,
                'action_url'  => '/dashboard/finance/program-approvals',
                'icon'        => 'dollar-sign',
                'color'       => 'amber',
                'is_read'     => false,
                'created_by'  => $actor->id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // Admin and Super Admin — link to program approvals (they can access it)
        $admins = User::where('is_active', true)
            ->whereIn('role', ['admin', 'super_admin'])
            ->select('id', 'role')
            ->get();

        foreach ($admins as $u) {
            if ($notifiedIds->contains($u->id)) {
                continue;
            }
            $notifiedIds->push($u->id);
            $rows->push([
                'user_id'     => $u->id,
                'type'        => 'finance_approval_request',
                'title'       => 'تم إرسال برنامج مدفوع للمراجعة المالية',
                'message'     => "تم إرسال البرنامج «{$program->title}» إلى المدير المالي للمراجعة قبل النشر. القيمة: {$price} {$currency}.",
                'entity_type' => $type,
                'entity_id'   => $program->id,
                'action_url'  => '/dashboard/finance/program-approvals',
                'icon'        => 'dollar-sign',
                'color'       => 'amber',
                'is_read'     => false,
                'created_by'  => $actor->id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // Creator confirmation (skip if creator is already notified above as admin/finance_manager)
        $creatorId = $program->created_by ?? null;
        if ($creatorId && ! $notifiedIds->contains($creatorId)) {
            $rows->push([
                'user_id'     => $creatorId,
                'type'        => 'finance_approval_request',
                'title'       => 'تم إرسال البرنامج للمراجعة المالية',
                'message'     => "تم إرسال البرنامج «{$program->title}» للمراجعة المالية، ولا يمكن نشره قبل الاعتماد.",
                'entity_type' => $type,
                'entity_id'   => $program->id,
                'action_url'  => '/dashboard/admin/programs',
                'icon'        => 'clock',
                'color'       => 'blue',
                'is_read'     => false,
                'created_by'  => $actor->id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        if ($rows->isNotEmpty()) {
            PlatformNotification::insert($rows->toArray());
        }

        AuditLogService::log(
            'PROGRAM_FINANCE_NOTIFICATION_SENT',
            $type,
            $program->id,
            $program->title,
            [
                'notified_count'       => $rows->count(),
                'finance_manager_count' => $managers->count(),
                'admin_count'          => $admins->count(),
                'price'                => $program->price,
                'currency'             => $currency,
            ],
            null,
            null,
        );
    }

    private function notifyApproval(Model $program, User $reviewer, ProgramFinanceApproval $approval): void
    {
        $now          = now();
        $type         = class_basename($program);
        $notifiedIds  = collect();
        $rows         = collect();

        // Notify creator
        $creatorId = $program->created_by ?? null;
        if ($creatorId) {
            $notifiedIds->push($creatorId);
            $rows->push([
                'user_id'     => $creatorId,
                'type'        => 'finance_approved',
                'title'       => 'تم اعتماد البرنامج مالياً',
                'message'     => "تم اعتماد البرنامج «{$program->title}» من قِبَل المدير المالي. يمكنك الآن نشره.",
                'entity_type' => $type,
                'entity_id'   => $program->id,
                'action_url'  => '/dashboard/admin/programs',
                'icon'        => 'check-circle',
                'color'       => 'green',
                'is_read'     => false,
                'created_by'  => $reviewer->id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // Notify admins/super_admins (if different from creator)
        $admins = User::where('is_active', true)
            ->whereIn('role', ['admin', 'super_admin'])
            ->select('id')
            ->get();

        foreach ($admins as $u) {
            if ($notifiedIds->contains($u->id)) {
                continue;
            }
            $notifiedIds->push($u->id);
            $rows->push([
                'user_id'     => $u->id,
                'type'        => 'finance_approved',
                'title'       => 'تم اعتماد البرنامج مالياً',
                'message'     => "تم اعتماد البرنامج «{$program->title}» من قِبَل المدير المالي ويمكن الآن نشره.",
                'entity_type' => $type,
                'entity_id'   => $program->id,
                'action_url'  => '/dashboard/admin/programs',
                'icon'        => 'check-circle',
                'color'       => 'green',
                'is_read'     => false,
                'created_by'  => $reviewer->id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        if ($rows->isNotEmpty()) {
            PlatformNotification::insert($rows->toArray());
        }
    }

    private function notifyRejection(Model $program, User $reviewer, ProgramFinanceApproval $approval): void
    {
        $creatorId = $program->created_by ?? null;
        if (! $creatorId) {
            return;
        }

        $now    = now();
        $reason = $approval->rejection_reason ?? '—';
        PlatformNotification::insert([[
            'user_id'     => $creatorId,
            'type'        => 'finance_rejected',
            'title'       => 'تم رفض البرنامج مالياً',
            'message'     => "رُفض البرنامج «{$program->title}». السبب: {$reason}",
            'entity_type' => class_basename($program),
            'entity_id'   => $program->id,
            'action_url'  => '/dashboard/admin/programs',
            'icon'        => 'x-circle',
            'color'       => 'red',
            'is_read'     => false,
            'created_by'  => $reviewer->id,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]]);
    }
}


