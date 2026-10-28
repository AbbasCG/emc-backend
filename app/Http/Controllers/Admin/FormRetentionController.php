<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DynamicForm;
use App\Services\Forms\FormActivityService;
use App\Services\Forms\FormRetentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How long a form's responses are kept. Anyone who may edit the form sees it; only those who manage the form's access (its owner and
 * platform managers) change it, because a shorter period deletes responses for good.
 */
class FormRetentionController extends Controller
{
    public function __construct(private readonly FormRetentionService $retention) {}

    public function show(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('update', $form);

        return response()->json(['success' => true, 'data' => $this->present($request, $form)]);
    }

    public function update(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('manageAccess', $form);
        $data = $request->validate([
            'retention_days' => ['present', 'nullable', 'integer', 'min:'.FormRetentionService::MIN_DAYS, 'max:'.FormRetentionService::MAX_DAYS],
        ], [
            'retention_days.min' => 'أقل مدة احتفاظ '.FormRetentionService::MIN_DAYS.' يوماً.',
            'retention_days.max' => 'أطول مدة احتفاظ '.FormRetentionService::MAX_DAYS.' يوماً.',
        ]);

        $before = $form->retention_days;
        $after = $data['retention_days'] === null ? null : (int) $data['retention_days'];
        if ($before !== $after) {
            $form->forceFill(['retention_days' => $after])->save();
            FormActivityService::record($form, $request->user(), 'retention_changed', [
                'from' => $before === null ? 'بلا حد' : "{$before} يوماً",
                'to'   => $after === null ? 'بلا حد' : "{$after} يوماً",
            ], $request);
        }

        return response()->json(['success' => true, 'data' => $this->present($request, $form->refresh())]);
    }

    /** @return array<string, mixed> */
    private function present(Request $request, DynamicForm $form): array
    {
        return [
            'retention_days' => $form->retention_days,
            'can_change'     => $request->user()->can('manageAccess', $form),
            'expired_now'    => $form->retention_days !== null ? $this->retention->expiredCount($form, (int) $form->retention_days) : 0,
            'min_days'       => FormRetentionService::MIN_DAYS,
            'max_days'       => FormRetentionService::MAX_DAYS,
        ];
    }
}
