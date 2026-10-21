<?php

namespace App\Services\Forms;

use App\Models\DynamicForm;
use App\Models\FormCollaborator;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who a form is shared with, and who owns it.
 *
 * The caller has already been authorized to manage access (owner or a platform-wide admin); this service enforces the rules
 * that hold regardless of who calls: only eligible employees can be given access, the owner is never also a collaborator,
 * a person appears once per form, response access is only granted by someone who has it, and ownership only moves to someone
 * who could manage the form. Every change is recorded in the form's history in the same transaction.
 */
class FormCollaborationService
{
    public function __construct(private readonly FormAccessService $access) {}

    /**
     * Employees this form could still be shared with, found by name. Only display facts are returned: no e-mail, phone or
     * any other personal detail.
     *
     * @return Collection<int, User>
     */
    public function candidates(DynamicForm $form, string $query): Collection
    {
        $taken = $form->collaborators()->pluck('user_id')->push($form->effectiveOwnerId())->filter()->all();
        $like = '%'.addcslashes(trim($query), '%_\\').'%';

        return User::query()
            ->where('is_active', true)
            ->whereIn('role', FormAccessService::ROLES)
            ->where('name', 'like', $like)
            ->whereNotIn('id', $taken ?: [0])
            ->with('departments:departments.id,departments.name')
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'role']);
    }

    public function add(DynamicForm $form, User $actor, int $userId, string $level, bool $canViewResponses, ?Request $request = null, bool $canCorrectResponses = false): FormCollaborator
    {
        $target = User::query()->find($userId);
        if ($target === null || ! $this->access->isEligibleEmployee($target)) {
            throw ValidationException::withMessages(['user_id' => 'هذا الموظف غير مؤهل للوصول إلى النماذج.']);
        }
        if ($form->effectiveOwnerId() === $target->id) {
            throw ValidationException::withMessages(['user_id' => 'مالك النموذج لديه وصول كامل بالفعل.']);
        }
        $this->assertMayGrantResponses($form, $actor, $canViewResponses);
        $this->assertMayGrantCorrection($form, $actor, $canViewResponses, $canCorrectResponses, false);

        try {
            return DB::transaction(function () use ($form, $actor, $target, $level, $canViewResponses, $canCorrectResponses, $request) {
                if (FormCollaborator::query()->where('form_id', $form->id)->where('user_id', $target->id)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['user_id' => 'تمت إضافة هذا الموظف مسبقاً. عدّل صلاحياته بدلاً من إضافته مرة أخرى.']);
                }

                $collaborator = FormCollaborator::create([
                    'form_id' => $form->id, 'user_id' => $target->id, 'access_level' => $level,
                    'can_view_responses' => $canViewResponses, 'can_correct_responses' => $canCorrectResponses, 'granted_by' => $actor->id,
                ]);
                FormActivityService::record($form, $actor, 'collaborator_added', ['user' => $target->name, 'access_level' => $level, 'can_view_responses' => $canViewResponses, 'can_correct_responses' => $canCorrectResponses], $request);

                return $collaborator;
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests added the same person at the same moment; the unique index let exactly one through.
            throw ValidationException::withMessages(['user_id' => 'تمت إضافة هذا الموظف مسبقاً.']);
        }
    }

    public function update(DynamicForm $form, FormCollaborator $collaborator, User $actor, string $level, bool $canViewResponses, ?Request $request = null, ?bool $canCorrectResponses = null): FormCollaborator
    {
        if ($canViewResponses && ! $collaborator->can_view_responses) {
            $this->assertMayGrantResponses($form, $actor, true);
        }
        // Not sent means unchanged. Correction needs response access, so taking that away takes this away too.
        $canCorrect = ($canCorrectResponses ?? (bool) $collaborator->can_correct_responses) && $canViewResponses;
        $this->assertMayGrantCorrection($form, $actor, $canViewResponses, $canCorrectResponses ?? false, (bool) $collaborator->can_correct_responses);

        return DB::transaction(function () use ($form, $collaborator, $actor, $level, $canViewResponses, $canCorrect, $request) {
            $from = ['access_level' => $collaborator->access_level, 'can_view_responses' => (bool) $collaborator->can_view_responses, 'can_correct_responses' => (bool) $collaborator->can_correct_responses];
            $collaborator->forceFill(['access_level' => $level, 'can_view_responses' => $canViewResponses, 'can_correct_responses' => $canCorrect, 'granted_by' => $actor->id])->save();

            if ($from !== ['access_level' => $level, 'can_view_responses' => $canViewResponses, 'can_correct_responses' => $canCorrect]) {
                FormActivityService::record($form, $actor, 'collaborator_updated', [
                    'user' => $collaborator->user?->name, 'from' => $from['access_level'], 'to' => $level, 'can_view_responses' => $canViewResponses, 'can_correct_responses' => $canCorrect,
                ], $request);
            }

            return $collaborator->refresh();
        });
    }

    public function remove(DynamicForm $form, FormCollaborator $collaborator, User $actor, ?Request $request = null): void
    {
        DB::transaction(function () use ($form, $collaborator, $actor, $request) {
            $name = $collaborator->user?->name;
            $collaborator->delete();
            FormActivityService::record($form, $actor, 'collaborator_removed', ['user' => $name], $request);
        });
    }

    /**
     * Moves ownership. The creator never changes (it is history). The new owner must be able to manage forms; the previous
     * owner keeps access only if the caller says so, as a viewer or editor, with response access as a separate choice.
     */
    public function transferOwnership(DynamicForm $form, User $actor, int $newOwnerId, ?string $keepAccess, bool $keepResponses, ?Request $request = null): DynamicForm
    {
        $newOwner = User::query()->find($newOwnerId);
        if ($newOwner === null || ! $this->access->isEligibleEmployee($newOwner) || ! $this->access->canManageAny($newOwner)) {
            throw ValidationException::withMessages(['owner_id' => 'لا يمكن نقل الملكية إلى هذا الموظف: يجب أن يكون مؤهلاً لإدارة النماذج.']);
        }
        if ($form->effectiveOwnerId() === $newOwner->id) {
            throw ValidationException::withMessages(['owner_id' => 'هذا الموظف هو المالك الحالي بالفعل.']);
        }

        return DB::transaction(function () use ($form, $actor, $newOwner, $keepAccess, $keepResponses, $request) {
            $locked = DynamicForm::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();
            $previousId = $locked->effectiveOwnerId();
            $previous = $previousId ? User::query()->find($previousId) : null;

            // The new owner no longer needs a collaborator row.
            FormCollaborator::query()->where('form_id', $locked->id)->where('user_id', $newOwner->id)->delete();
            $locked->forceFill(['owner_id' => $newOwner->id])->save();

            if ($previous !== null && in_array($keepAccess, FormCollaborator::LEVELS, true)) {
                FormCollaborator::query()->updateOrCreate(
                    ['form_id' => $locked->id, 'user_id' => $previous->id],
                    ['access_level' => $keepAccess, 'can_view_responses' => $keepResponses, 'granted_by' => $actor->id],
                );
            }

            FormActivityService::record($locked, $actor, 'ownership_changed', [
                'from' => $previous?->name, 'to' => $newOwner->name,
                'previous_owner_access' => in_array($keepAccess, FormCollaborator::LEVELS, true) ? $keepAccess : 'none',
            ], $request);

            return $locked->refresh();
        });
    }

    /** Correction is a grant on top of response access, handed on only by someone who may correct themselves. */
    private function assertMayGrantCorrection(DynamicForm $form, User $actor, bool $canViewResponses, bool $grantsCorrection, bool $alreadyHad): void
    {
        if ($grantsCorrection && ! $canViewResponses) {
            throw ValidationException::withMessages(['can_correct_responses' => 'لا يمكن منح صلاحية تصحيح الإجابات دون صلاحية عرض الإجابات.']);
        }
        if ($grantsCorrection && ! $alreadyHad && ! $this->access->canCorrectResponses($actor, $form)) {
            throw ValidationException::withMessages(['can_correct_responses' => 'لا يمكنك منح صلاحية تصحيح الإجابات لأنك لا تملكها على هذا النموذج.']);
        }
    }

    /** Response access can only be handed on by someone who has it themselves. */
    private function assertMayGrantResponses(DynamicForm $form, User $actor, bool $grantsResponses): void
    {
        if ($grantsResponses && ! $this->access->canViewResponses($actor, $form)) {
            throw ValidationException::withMessages(['can_view_responses' => 'لا يمكنك منح صلاحية عرض الإجابات لأنك لا تملكها على هذا النموذج.']);
        }
    }
}
