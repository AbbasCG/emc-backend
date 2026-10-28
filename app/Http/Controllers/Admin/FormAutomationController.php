<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DynamicForm;
use App\Models\WebhookEndpoint;
use App\Services\Forms\Automation\FormAutomationSettings;
use App\Services\Forms\FormAccessService;
use App\Services\Forms\FormActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A form's notifications and automation (Smart Forms Phase 8). Whoever may edit the form may change them (the same `update`
 * ability as the builder); sending answers to external systems additionally needs a platform-wide manager (FormAutomationSettings).
 */
class FormAutomationController extends Controller
{
    public function __construct(private readonly FormAccessService $access) {}

    public function show(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('update', $form);

        return response()->json(['success' => true, 'data' => $this->present($request, $form)]);
    }

    public function update(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('update', $form);
        $input = $request->validate([
            'notify_owner'                   => ['sometimes', 'boolean'],
            'notify_department'              => ['sometimes', 'boolean'],
            'notify_assignee'                => ['sometimes', 'boolean'],
            'confirmation'                   => ['sometimes', 'array'],
            'confirmation.enabled'           => ['sometimes', 'boolean'],
            'confirmation.email_question_id' => ['sometimes', 'nullable', 'integer'],
            'auto_assign_to'                 => ['sometimes', 'nullable', 'integer'],
            'reminder_days'                  => ['sometimes', 'nullable', 'integer'],
            'webhook'                        => ['sometimes', 'boolean'],
            'webhook_include_answers'        => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $form, $input) {
            $locked = DynamicForm::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();
            $before = FormAutomationSettings::of($locked);
            $after = FormAutomationSettings::apply($locked, $input, $request->user(), $this->access);
            $changed = FormAutomationSettings::changed($before, $after);
            if ($changed === []) {
                return;
            }
            $locked->forceFill(['automation' => $after])->save();
            FormActivityService::record($locked, $request->user(), 'automation_changed', ['changes' => $changed], $request);
        });

        return response()->json(['success' => true, 'data' => $this->present($request, $form->refresh())]);
    }

    /** @return array<string, mixed> */
    private function present(Request $request, DynamicForm $form): array
    {
        return [
            'settings' => FormAutomationSettings::of($form),
            'options'  => [
                'email_questions' => $form->questions()->where('type', 'email')->get(['id', 'label'])->map(fn ($q) => ['id' => $q->id, 'label' => $q->label])->values(),
                'assignable'      => $this->access->assignableEmployees($form)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
                'has_department'  => $form->department_id !== null,
                'can_include_answers' => $this->access->isPlatformWide($request->user()),
                // Whether anything would receive the event: listening endpoints are managed by platform admins elsewhere.
                'webhook_endpoints' => WebhookEndpoint::query()->where('status', 'active')->get()->filter(fn ($e) => $e->listensTo('form.submitted'))->count(),
                'max_reminder_days' => FormAutomationSettings::MAX_REMINDER_DAYS,
            ],
        ];
    }
}
