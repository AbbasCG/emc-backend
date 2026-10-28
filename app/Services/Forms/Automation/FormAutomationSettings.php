<?php

namespace App\Services\Forms\Automation;

use App\Models\DynamicForm;
use App\Models\FormQuestion;
use App\Models\User;
use App\Services\Forms\FormAccessService;
use Illuminate\Validation\ValidationException;

/**
 * The automation settings of a form (Smart Forms Phase 8), their defaults and their rules.
 *
 *   notify_owner              the owner gets an in-app notification for every new response
 *   notify_department         the leaders of the form's department who may read its responses get one too
 *   notify_assignee           an employee is notified when a response is assigned to them (on by default)
 *   confirmation              the respondent gets a confirmation: in-app and by e-mail when signed in, or by e-mail to the
 *                             address they typed in the chosen e-mail question
 *   auto_assign_to            new responses are assigned to this reviewer at once (the start of the review workflow)
 *   reminder_days             reviewers are reminded, once a day, of responses still open after this many days
 *   webhook                   the `form.submitted` event is sent to the webhook endpoints that listen to it
 *   webhook_include_answers   the event carries the answers too; only a platform-wide manager may switch this on
 *
 * Defaults keep the behaviour forms had before this phase: nothing is sent except the assignment notice.
 */
final class FormAutomationSettings
{
    public const DEFAULTS = [
        'notify_owner'            => false,
        'notify_department'       => false,
        'notify_assignee'         => true,
        'confirmation'            => ['enabled' => false, 'email_question_id' => null],
        'auto_assign_to'          => null,
        'reminder_days'           => null,
        'webhook'                 => false,
        'webhook_include_answers' => false,
    ];

    public const MAX_REMINDER_DAYS = 30;

    /** @return array<string, mixed> the stored settings over the defaults */
    public static function of(DynamicForm $form): array
    {
        $stored = is_array($form->automation) ? $form->automation : [];
        $out = array_replace(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        $out['confirmation'] = array_replace(self::DEFAULTS['confirmation'], is_array($stored['confirmation'] ?? null) ? array_intersect_key($stored['confirmation'], self::DEFAULTS['confirmation']) : []);

        return $out;
    }

    /**
     * The settings after a change, checked against the form and the person making it. Unknown keys are dropped.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function apply(DynamicForm $form, array $input, User $actor, FormAccessService $access): array
    {
        $current = self::of($form);
        $next = $current;
        $errors = [];

        foreach (['notify_owner', 'notify_department', 'notify_assignee', 'webhook', 'webhook_include_answers'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $next[$flag] = filter_var($input[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if ($next['notify_department'] && $form->department_id === null) {
            $errors['notify_department'] = 'هذا النموذج غير مرتبط بقسم. اختر قسماً للنموذج أولاً.';
        }
        // Answers leave the platform only on a decision taken by someone who answers for the whole platform.
        if ($next['webhook_include_answers'] && ! $current['webhook_include_answers'] && ! $access->isPlatformWide($actor)) {
            $errors['webhook_include_answers'] = 'إرسال الإجابات إلى أنظمة خارجية يحتاج قراراً من مدير المنصة.';
        }
        if (! $next['webhook']) {
            $next['webhook_include_answers'] = false;
        }

        if (array_key_exists('confirmation', $input) && is_array($input['confirmation'])) {
            $c = $input['confirmation'];
            if (array_key_exists('enabled', $c)) {
                $next['confirmation']['enabled'] = filter_var($c['enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            if (array_key_exists('email_question_id', $c)) {
                $id = $c['email_question_id'] === null || $c['email_question_id'] === '' ? null : (int) $c['email_question_id'];
                if ($id !== null && ! FormQuestion::query()->where('form_id', $form->id)->whereKey($id)->where('type', 'email')->exists()) {
                    $errors['confirmation.email_question_id'] = 'اختر سؤالاً من نوع البريد الإلكتروني في هذا النموذج.';
                }
                $next['confirmation']['email_question_id'] = $id;
            }
        }

        if (array_key_exists('auto_assign_to', $input)) {
            $id = $input['auto_assign_to'] === null || $input['auto_assign_to'] === '' ? null : (int) $input['auto_assign_to'];
            if ($id !== null && ! $access->assignableEmployees($form)->contains('id', $id)) {
                $errors['auto_assign_to'] = 'لا يمكن الإسناد التلقائي لهذا الموظف: يجب أن يملك صلاحية مراجعة ردود هذا النموذج.';
            }
            $next['auto_assign_to'] = $id;
        }

        if (array_key_exists('reminder_days', $input)) {
            $days = $input['reminder_days'] === null || $input['reminder_days'] === '' ? null : filter_var($input['reminder_days'], FILTER_VALIDATE_INT);
            if ($days === false || ($days !== null && ($days < 1 || $days > self::MAX_REMINDER_DAYS))) {
                $errors['reminder_days'] = 'اختر عدد أيام بين 1 و'.self::MAX_REMINDER_DAYS.'، أو أوقف التذكير.';
            } else {
                $next['reminder_days'] = $days;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $next;
    }

    /** @return list<string> the settings that differ, by key, for the history */
    public static function changed(array $before, array $after): array
    {
        return array_values(array_filter(array_keys(self::DEFAULTS), fn ($k) => json_encode($before[$k] ?? null) !== json_encode($after[$k] ?? null)));
    }
}
