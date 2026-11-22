<?php

namespace App\Services;

use App\Jobs\DispatchWebhookJob;
use App\Jobs\SendWhatsAppJob;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Automation Engine — Phase 7 expansion.
 *
 * Finds all active AutomationRules that match a trigger event,
 * evaluates conditions, and executes queued actions.
 *
 * Usage:
 *   app(AutomationEngineService::class)->handle('payment_confirmed', [
 *       'user_id'    => 42,
 *       'course_id'  => 5,
 *       'amount'     => 99.99,
 *   ]);
 */
class AutomationEngineService
{
    public const SUPPORTED_TRIGGERS = [
        'registration_created',
        'payment_confirmed',
        'payment_failed',
        'session_starts_soon',
        'assignment_due_soon',
        'certificate_issued',
        'task_overdue',
        'support_ticket_created',
        'partner_request_created',
    ];

    public const SUPPORTED_ACTIONS = [
        'send_in_app_notification',
        'send_email',
        'send_whatsapp',
        'create_task',
        'create_meeting',
        'call_webhook',
        'update_status',
    ];

    /**
     * Handle a trigger event — run all matching active automation rules.
     *
     * @param  string  $trigger  One of self::SUPPORTED_TRIGGERS
     * @param  array   $context  Event payload / model data
     */
    public function handle(string $trigger, array $context = []): void
    {
        AutomationRule::query()
            ->where('trigger_type', $trigger)
            ->where('status', 'active')
            ->get()
            ->each(fn (AutomationRule $rule) => $this->executeRule($rule, $context));
    }

    private function executeRule(AutomationRule $rule, array $context): void
    {
        if (! $this->evaluateConditions($rule->conditions ?? [], $context)) {
            return;
        }

        $run = AutomationRun::create([
            'rule_id'    => $rule->id,
            'status'     => 'running',
            'context'    => $context,
            'started_at' => now(),
        ]);

        try {
            foreach ($rule->actions ?? [] as $action) {
                $this->executeAction($action, $context);
            }

            $run->update(['status' => 'completed', 'finished_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('AutomationEngine: rule failed', [
                'rule_id' => $rule->id,
                'error'   => $e->getMessage(),
            ]);
            $run->update(['status' => 'failed', 'error_message' => $e->getMessage(), 'finished_at' => now()]);
        }
    }

    /**
     * Simple condition evaluator. Each condition:
     *   { "field": "amount", "operator": ">", "value": 50 }
     */
    private function evaluateConditions(array $conditions, array $context): bool
    {
        foreach ($conditions as $condition) {
            $field    = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? '==';
            $expected = $condition['value'] ?? null;
            $actual   = data_get($context, $field);

            $pass = match ($operator) {
                '=='  => $actual == $expected,
                '!='  => $actual != $expected,
                '>'   => $actual > $expected,
                '>='  => $actual >= $expected,
                '<'   => $actual < $expected,
                '<='  => $actual <= $expected,
                'in'  => is_array($expected) && in_array($actual, $expected),
                default => true,
            };

            if (! $pass) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute a single action from the rule's actions array.
     *
     * Action shape:
     *   { "type": "send_email", "to": "{{email}}", "subject": "Hi {{name}}", "body": "..." }
     */
    private function executeAction(array $action, array $context): void
    {
        $type = $action['type'] ?? null;

        match ($type) {
            'send_in_app_notification' => $this->actionInApp($action, $context),
            'send_email'               => $this->actionEmail($action, $context),
            'send_whatsapp'            => $this->actionWhatsApp($action, $context),
            'call_webhook'             => $this->actionWebhook($action, $context),
            'create_task'              => $this->actionCreateTask($action, $context),
            default                    => Log::info("AutomationEngine: unknown action [{$type}]"),
        };
    }

    private function actionInApp(array $action, array $context): void
    {
        $userId = data_get($context, 'user_id');
        if (! $userId) {
            return;
        }

        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }

        app(NotificationDispatcher::class)->send(
            $user,
            $action['notification_type'] ?? 'automation_triggered',
            $context,
            ['in_app'],
        );
    }

    private function actionEmail(array $action, array $context): void
    {
        $to      = $this->interpolate($action['to'] ?? '', $context);
        $subject = $this->interpolate($action['subject'] ?? 'Notification', $context);
        $body    = $this->interpolate($action['body'] ?? '', $context);

        if ($to && $subject && $body) {
            EmailService::dispatch('automation_email', $to, $to, null,
                new \App\Mail\RawContentMail($subject, $body), $subject,
                ['rule_action' => 'send_email']);
        }
    }

    private function actionWhatsApp(array $action, array $context): void
    {
        $to   = $this->interpolate($action['to'] ?? '', $context);
        $type = $action['notification_type'] ?? 'automation_triggered';

        if ($to) {
            SendWhatsAppJob::dispatch($to, $type, $context, data_get($context, 'user_id'));
        }
    }

    private function actionWebhook(array $action, array $context): void
    {
        // Call an arbitrary URL from the action config
        $url = $action['url'] ?? null;
        if (! $url) {
            return;
        }

        // We create a temporary endpoint record, or just queue a direct HTTP call
        \Illuminate\Support\Facades\Http::withOptions(['timeout' => 10])
            ->post($url, ['trigger' => 'automation', 'context' => $context]);
    }

    private function actionCreateTask(array $action, array $context): void
    {
        if (! class_exists(\App\Models\Task::class)) {
            return;
        }

        \App\Models\Task::create([
            'title'       => $this->interpolate($action['title'] ?? 'Auto-created task', $context),
            'description' => $this->interpolate($action['description'] ?? '', $context),
            'assigned_to' => $action['assigned_to'] ?? data_get($context, 'user_id'),
            'status'      => 'open',
            'created_by'  => null,
        ]);
    }

    /** Simple {{variable}} interpolation in strings. */
    private function interpolate(string $template, array $context): string
    {
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $template = str_replace("{{{$key}}}", (string) $value, $template);
            }
        }

        return $template;
    }
}
