<?php

namespace App\Services;

use App\Exceptions\TicketActionException;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\User;

/**
 * The ONE implementation of "what planning estimate must a ticket carry when it becomes work for a person".
 *
 * Used by approve (with an assignee), reassign, bulk reassign and the work-estimate endpoint, so no path
 * can assign a ticket under weaker rules than another. The points ranges are NOT copied here: they come
 * from TaskWorkflowService::validatePointsForScope, the same rule the tasks module applies.
 *
 * The estimate is planning data only and nothing here credits anything. Points are credited once, at the final approval
 * of the completion (TicketCreditService), from the stored task_points; the hours credited there are the reviewer's
 * ACTUAL duration, never `estimated_hours`.
 */
class TicketWorkEstimate
{
    public const SCOPES = [Task::SCOPE_SIMPLE, Task::SCOPE_MEDIUM, Task::SCOPE_LARGE, Task::SCOPE_STRATEGIC];

    public const SCOPE_LABELS = [
        Task::SCOPE_SIMPLE => 'بسيطة',
        Task::SCOPE_MEDIUM => 'متوسطة',
        Task::SCOPE_LARGE => 'كبيرة',
        Task::SCOPE_STRATEGIC => 'استراتيجية',
    ];

    /** Sanity bounds on the TYPE of the input; the business range per scope is validatePointsForScope's. */
    public const MAX_POINTS = 1000000;
    public const MAX_HOURS = 1000;
    /** Hours are stored with 2 decimals, so anything that rounds to 0.00 is not an estimate. */
    public const MIN_HOURS = 0.01;

    public const MISSING_MESSAGE = 'حدّد نقاط المهمة وحجمها قبل الإسناد';

    public const KEYS = ['task_scope', 'task_points', 'estimated_hours'];

    /** The «requires a live deployment» flag a manager may set together with the estimate (optional boolean; absent = keep). */
    public const FLAG = 'requires_deployment';

    /** @return array<string, array<int, mixed>> */
    public static function flagRules(): array
    {
        return [self::FLAG => ['sometimes', 'nullable', 'boolean']];
    }

    /** @return array<string, string> */
    public static function flagMessages(): array
    {
        return [self::FLAG.'.boolean' => 'قيمة «يتطلب نشرًا على الموقع الحي» غير صالحة.'];
    }

    /** The flag as sent: true/false, or null when it was not sent (keep the stored value). */
    public static function flagFrom(array $source): ?bool
    {
        if (! array_key_exists(self::FLAG, $source) || $source[self::FLAG] === null || $source[self::FLAG] === '') {
            return null;
        }

        return filter_var($source[self::FLAG], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /** Apply the flag to the (locked) ticket when it changes it, and record the change (internal row). Returns whether it changed. */
    public static function applyFlag(Ticket $ticket, User $actor, ?bool $flag): bool
    {
        if ($flag === null || (bool) $ticket->requires_deployment === $flag) {
            return false;
        }

        // Once the work has been submitted for review at least once, the ticket can no longer LEAVE the deployment flow (a
        // failed verification must not become a way to close it without a live check). Switching it ON stays possible.
        if ($flag === false && (int) $ticket->review_round > 0) {
            throw new TicketActionException('لا يمكن إلغاء اشتراط النشر بعد أن أُرسلت التذكرة للمراجعة مرة واحدة على الأقل.', 409, [], 'DEPLOYMENT_FLAG_LOCKED');
        }

        $ticket->forceFill([self::FLAG => $flag])->save();
        TicketActivityLog::create([
            'ticket_id' => $ticket->id,
            'action' => 'DEPLOYMENT_FLAG_CHANGED',
            'previous_state' => null,
            'new_state' => null,
            'performed_by' => $actor->id,
            'performed_by_name' => \Illuminate\Support\Str::limit((string) $actor->name, 150, ''),
            'details' => $flag ? 'تحديد التذكرة كمهمة تتطلب نشرًا على الموقع الحي' : 'إلغاء اشتراط النشر على الموقع الحي لهذه التذكرة',
            'logged_at' => now(),
        ]);

        return true;
    }

    /**
     * Laravel rules for the three request fields (shape only; the combination is checked by resolve()).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'task_scope' => ['nullable', 'string', 'in:'.implode(',', self::SCOPES)],
            'task_points' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_POINTS],
            'estimated_hours' => ['nullable', 'numeric', 'gte:'.self::MIN_HOURS, 'max:'.self::MAX_HOURS],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'task_scope.string' => 'حجم المهمة غير صالح (بسيطة، متوسطة، كبيرة أو استراتيجية).',
            'task_scope.in' => 'حجم المهمة غير صالح (بسيطة، متوسطة، كبيرة أو استراتيجية).',
            'task_points.integer' => 'نقاط المهمة يجب أن تكون عددًا صحيحًا.',
            'task_points.min' => 'نقاط المهمة يجب أن تكون أكبر من صفر.',
            'task_points.max' => 'نقاط المهمة أكبر من الحد المسموح.',
            'estimated_hours.numeric' => 'المدة التقريبية يجب أن تكون رقمًا.',
            'estimated_hours.gte' => 'المدة التقريبية يجب ألا تقل عن 0.01 ساعة.',
            'estimated_hours.max' => 'المدة التقريبية لا تتجاوز '.self::MAX_HOURS.' ساعة.',
        ];
    }

    /**
     * Only the estimate keys that were really sent (a key sent as null counts as sent: for hours it clears).
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public static function only(array $source): array
    {
        return array_intersect_key($source, array_flip(self::KEYS));
    }

    /**
     * The estimate the ticket must carry once $input is applied over what it already stores, or a 422
     * explaining what is missing / out of range. Never invents a value: a ticket with no stored estimate
     * and no input is refused.
     *
     * Hours: a value replaces the stored one; null / absent KEEPS it (approve, reassign, bulk). Only the
     * dedicated estimate endpoint passes $allowClearHours, where an explicit null clears them.
     *
     * @param  array<string, mixed>  $input  request values (any subset of KEYS)
     * @return array{task_scope: string, task_points: int, estimated_hours: ?float}
     *
     * @throws TicketActionException
     */
    public static function resolve(Ticket $ticket, array $input, bool $allowClearHours = false, ?string $missingMessage = null): array
    {
        $missingText = $missingMessage ?? self::MISSING_MESSAGE;
        $given = fn (string $k) => array_key_exists($k, $input) && $input[$k] !== null && $input[$k] !== '';

        $scope = $given('task_scope') ? (string) $input['task_scope'] : ($ticket->task_scope !== null ? (string) $ticket->task_scope : null);
        $rawPoints = $given('task_points') ? $input['task_points'] : $ticket->task_points;
        $hours = $given('estimated_hours')
            ? $input['estimated_hours']
            : ($allowClearHours && array_key_exists('estimated_hours', $input) ? null : $ticket->estimated_hours);

        $errors = [];

        if ($scope === null) {
            $errors['task_scope'] = [$missingText];
        } elseif (! in_array($scope, self::SCOPES, true)) {
            $errors['task_scope'] = ['حجم المهمة غير صالح (بسيطة، متوسطة، كبيرة أو استراتيجية).'];
        }

        $points = null;
        if ($rawPoints === null) {
            $errors['task_points'] = [$missingText];
        } else {
            $points = filter_var($rawPoints, FILTER_VALIDATE_INT);
            if ($points === false || $points < 1 || $points > self::MAX_POINTS) {
                $points = null;
                $errors['task_points'] = ['نقاط المهمة يجب أن تكون عددًا صحيحًا موجبًا.'];
            }
        }

        if ($errors === [] && $scope !== null && $points !== null) {
            $check = TaskWorkflowService::validatePointsForScope($scope, $points);
            if (! ($check['valid'] ?? false)) {
                $errors['task_points'] = [$check['message'] ?? 'نقاط المهمة خارج النطاق المسموح لحجمها.'];
            }
        }

        if ($hours !== null) {
            if (! is_numeric($hours) || round((float) $hours, 2) < self::MIN_HOURS || (float) $hours > self::MAX_HOURS) {
                $errors['estimated_hours'] = ['المدة التقريبية يجب أن تكون بين '.self::MIN_HOURS.' و'.self::MAX_HOURS.' ساعة.'];
            }
        }

        if ($errors !== []) {
            $missing = isset($errors['task_scope']) && $errors['task_scope'][0] === $missingText
                || isset($errors['task_points']) && $errors['task_points'][0] === $missingText;
            $message = $missing ? $missingText : (string) (reset($errors)[0] ?? $missingText);

            throw new TicketActionException($message, 422, $errors);
        }

        return [
            'task_scope' => $scope,
            'task_points' => $points,
            'estimated_hours' => $hours === null ? null : round((float) $hours, 2),
        ];
    }

    /** Arabic one-liner for an activity log: «الحجم: متوسطة، النقاط: 100، المدة: 2.5 ساعة». */
    public static function describe(?string $scope, $points, $hours): string
    {
        if ($scope === null && $points === null && $hours === null) {
            return 'غير محدد';
        }

        $parts = [];
        $parts[] = 'الحجم: '.($scope !== null ? (self::SCOPE_LABELS[$scope] ?? $scope) : '—');
        $parts[] = 'النقاط: '.($points !== null ? (int) $points : '—');
        $parts[] = 'المدة: '.($hours !== null ? rtrim(rtrim(number_format((float) $hours, 2, '.', ''), '0'), '.').' ساعة' : '—');

        return implode('، ', $parts);
    }

    /** @return array{task_scope: ?string, task_points: ?int, estimated_hours: ?float} */
    public static function snapshot(Ticket $ticket): array
    {
        return [
            'task_scope' => $ticket->task_scope,
            'task_points' => $ticket->task_points === null ? null : (int) $ticket->task_points,
            'estimated_hours' => $ticket->estimated_hours === null ? null : round((float) $ticket->estimated_hours, 2),
        ];
    }

    /**
     * One ESTIMATE_UPDATED activity row. previous_state / new_state are string(50) columns, so they only ever
     * get a SHORT code («ESTIMATE:<scope>» or null, at most 18 characters); the readable before/after text
     * lives in `details`, which has no such limit. These rows are visible to managers, global roles and the
     * assignee only (TicketAccess::redactInternal), which is why approve/reassign never put estimate text in
     * their own, widely visible, log rows.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function log(Ticket $ticket, User $actor, array $before, array $after): void
    {
        TicketActivityLog::create([
            'ticket_id' => $ticket->id,
            'action' => 'ESTIMATE_UPDATED',
            'previous_state' => $before['task_scope'] ? 'ESTIMATE:'.$before['task_scope'] : null,
            'new_state' => $after['task_scope'] ? 'ESTIMATE:'.$after['task_scope'] : null,
            'performed_by' => $actor->id,
            'performed_by_name' => $actor->name,
            'details' => 'تعديل تقدير العمل من ('.self::describe($before['task_scope'], $before['task_points'], $before['estimated_hours']).') إلى ('.self::describe($after['task_scope'], $after['task_points'], $after['estimated_hours']).')',
            'logged_at' => now(),
        ]);
    }

    /** True when applying $estimate changes anything stored on the ticket. */
    public static function differs(Ticket $ticket, array $estimate): bool
    {
        return $ticket->task_scope !== $estimate['task_scope']
            || (int) $ticket->task_points !== (int) $estimate['task_points']
            || $ticket->task_points === null
            || ($ticket->estimated_hours === null ? null : round((float) $ticket->estimated_hours, 2)) !== $estimate['estimated_hours'];
    }
}
