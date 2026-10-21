<?php

namespace App\Services\Forms;

use App\Models\DynamicForm;
use App\Models\FormCollaborator;
use App\Models\User;
use App\Services\DepartmentAccessService;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Who may see, change, publish, share and read the responses of a form. The single authority: the list, the single
 * record, the response list, attachments, history, versions and collaborator management all ask here, so they can never
 * disagree.
 *
 * Business authorization only. The role middleware on the routes decides who may reach the forms screens (ROLES) and the
 * page-access layer decides who sees the menu entry; neither grants anything here.
 *
 *  OPEN THE FORM (definition, versions, history)
 *    platform-wide roles (super_admin, tech_admin, executive_admin, admin) always; otherwise `view_forms` AND one of:
 *    the owner (the creator when no owner is recorded), a collaborator, or — for an `organization` form — the form's scope:
 *    no department means everyone with `view_forms`, a department means its members and leaders (DepartmentAccessService).
 *    A `private` form ignores the scope entirely.
 *  CHANGE / PUBLISH / CLOSE / ARCHIVE / RESTORE
 *    `manage_forms` AND (platform-wide role, owner, editor collaborator, or the organisation scope above).
 *    executive_admin holds only `view_forms`, so it stays read-only.
 *  SHARE (collaborators, ownership, visibility, delete)
 *    the owner and the platform-wide roles that may manage. An editor can change the form but never who can reach it.
 *  READ THE RESPONSES (and, later, export exactly those)
 *    platform-wide roles; the owner; a collaborator granted `can_view_responses`; and everyone who can open the form
 *    when the form's responses_visibility is `form_viewers`. Editing and organisation-wide visibility grant nothing here.
 *    There is deliberately no separate export permission: whoever may read a set of responses may export exactly that set.
 *  REVIEW (status, assignment, internal notes, and reading the notes and the correction history)
 *    `manage_forms` AND may read the responses AND may change the form (canManageResponses).
 *  CORRECT AN ANSWER
 *    a separate, explicit grant: `manage_forms`, may read the responses, and is a platform-wide manager, the owner, or a
 *    collaborator granted `can_correct_responses`. Reading responses, or editing the form, never implies it.
 *
 * Existing forms keep visibility `organization` and responses `form_viewers`, which is exactly the Phase 0 behaviour.
 */
class FormAccessService
{
    /** Roles the forms routes let through, and therefore the only roles that can be made collaborators. */
    public const ROLES = ['admin', 'super_admin', 'tech_admin', 'operations_manager', 'community_manager'];

    /** @var array<string, FormCollaborator|null> */
    private array $collaborations = [];

    public function __construct(private readonly DepartmentAccessService $departments) {}

    public function isPlatformWide(User $user): bool
    {
        return $this->departments->isGlobal($user);
    }

    public function canViewAny(User $user): bool
    {
        return $this->isPlatformWide($user) || $user->hasPermission(Permissions::VIEW_FORMS);
    }

    public function canManageAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isTechAdmin() || $user->hasPermission(Permissions::MANAGE_FORMS);
    }

    public function isOwner(User $user, DynamicForm $form): bool
    {
        return $form->effectiveOwnerId() === (int) $user->id;
    }

    public function collaboration(User $user, DynamicForm $form): ?FormCollaborator
    {
        $key = $form->id.':'.$user->id;
        if (! array_key_exists($key, $this->collaborations)) {
            $this->collaborations[$key] = FormCollaborator::query()->where('form_id', $form->id)->where('user_id', $user->id)->first();
        }

        return $this->collaborations[$key];
    }

    /** Loads this user's collaborations for many forms in one query (the list screen). */
    public function prime(User $user, array $formIds): void
    {
        $found = FormCollaborator::query()->where('user_id', $user->id)->whereIn('form_id', $formIds)->get()->keyBy('form_id');
        foreach ($formIds as $id) {
            $this->collaborations[$id.':'.$user->id] = $found->get($id);
        }
    }

    public function canView(User $user, DynamicForm $form): bool
    {
        if ($this->isPlatformWide($user)) {
            return true;
        }
        if (! $this->canViewAny($user)) {
            return false;
        }
        if ($this->isOwner($user, $form) || $this->collaboration($user, $form) !== null) {
            return true;
        }

        return $form->visibility !== 'private' && $this->withinScope($user, $form);
    }

    public function canManage(User $user, DynamicForm $form): bool
    {
        if (! $this->canManageAny($user)) {
            return false;
        }
        if ($this->isPlatformWide($user) || $this->isOwner($user, $form)) {
            return true;
        }
        if ($this->collaboration($user, $form)?->access_level === FormCollaborator::EDITOR) {
            return true;
        }

        return $form->visibility !== 'private' && $this->withinScope($user, $form);
    }

    /** Collaborators, ownership, visibility and deletion: the owner, and platform-wide roles that may manage. */
    public function canManageAccess(User $user, DynamicForm $form): bool
    {
        return $this->canManageAny($user) && ($this->isPlatformWide($user) || $this->isOwner($user, $form));
    }

    public function canViewResponses(User $user, DynamicForm $form): bool
    {
        if ($this->isPlatformWide($user)) {
            return true;
        }
        if (! $this->canView($user, $form)) {
            return false;
        }
        if ($this->isOwner($user, $form)) {
            return true;
        }
        if ($this->collaboration($user, $form)?->can_view_responses) {
            return true;
        }

        return $form->responses_visibility === 'form_viewers';
    }

    public function canManageResponses(User $user, DynamicForm $form): bool
    {
        return $this->canManage($user, $form) && $this->canViewResponses($user, $form);
    }

    /** Correcting what a respondent submitted is its own grant; see the class comment. */
    public function canCorrectResponses(User $user, DynamicForm $form): bool
    {
        if (! $this->canManageAny($user) || ! $this->canViewResponses($user, $form)) {
            return false;
        }

        return $this->isPlatformWide($user) || $this->isOwner($user, $form) || (bool) $this->collaboration($user, $form)?->can_correct_responses;
    }

    /**
     * The employees a response of this form may be assigned to: active forms staff who can review its responses themselves.
     * Someone who could not open the response is never offered, and never accepted.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function assignableEmployees(DynamicForm $form): \Illuminate\Support\Collection
    {
        $candidates = User::query()->where('is_active', true)->whereIn('role', self::ROLES)->orderBy('name')->get(['id', 'name', 'role', 'is_active']);
        $found = FormCollaborator::query()->where('form_id', $form->id)->whereIn('user_id', $candidates->pluck('id'))->get()->keyBy('user_id');
        foreach ($candidates as $candidate) {
            $this->collaborations[$form->id.':'.$candidate->id] = $found->get($candidate->id);
        }

        return $candidates->filter(fn (User $u) => $this->isEligibleEmployee($u) && $this->canManageResponses($u, $form))->values();
    }

    /** May this user put a (new or moved) form into this department? Null means organisation-wide. */
    public function canUseDepartment(User $user, ?int $departmentId): bool
    {
        if ($departmentId === null || $this->isPlatformWide($user)) {
            return true;
        }

        return $this->departments->canAccessDepartment($user, $departmentId);
    }

    /** Can this account be given access to forms at all (so it can be a collaborator or owner)? */
    public function isEligibleEmployee(User $user): bool
    {
        return (bool) $user->is_active
            && in_array($user->role, self::ROLES, true)
            && ($user->isSuperAdmin() || $user->isTechAdmin() || $user->hasPermission(Permissions::VIEW_FORMS));
    }

    /** What this user may do with the form, for the screens to show or hide controls. The server checks again on every request. */
    public function abilities(User $user, DynamicForm $form): array
    {
        $canManage = $this->canManage($user, $form);

        return [
            'view'             => $this->canView($user, $form),
            'edit'             => $canManage,
            'publish'          => $canManage,
            'manage_access'    => $this->canManageAccess($user, $form),
            // Sharing: anyone who may change the form can SEE how it is shared; only those who manage access change it.
            'view_sharing'     => $canManage || $this->canManageAccess($user, $form),
            'manage_sharing'   => $this->canManageAccess($user, $form),
            'view_responses'   => $this->canViewResponses($user, $form),
            'manage_responses' => $this->canManageResponses($user, $form),
            'correct_responses' => $this->canCorrectResponses($user, $form),
            'delete'           => $this->canManageAccess($user, $form),
        ];
    }

    /** Restrict a form query to what this user may open. Caller must have passed canViewAny(). */
    public function scope(Builder $query, User $user): Builder
    {
        if ($this->isPlatformWide($user)) {
            return $query;
        }

        $departmentIds = $this->departments->getAccessibleDepartmentIds($user)->all();

        return $query->where(function (Builder $q) use ($user, $departmentIds) {
            $q->where('dynamic_forms.owner_id', $user->id)
                ->orWhere(fn (Builder $w) => $w->whereNull('dynamic_forms.owner_id')->where('dynamic_forms.created_by', $user->id))
                ->orWhereExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('form_collaborators')
                    ->whereColumn('form_collaborators.form_id', 'dynamic_forms.id')->where('form_collaborators.user_id', $user->id))
                ->orWhere(fn (Builder $w) => $w->where('dynamic_forms.visibility', '!=', 'private')
                    ->where(fn (Builder $d) => $d->whereNull('dynamic_forms.department_id')->orWhereIn('dynamic_forms.department_id', $departmentIds ?: [0])));
        });
    }

    /** The organisation scope of a form: no department means everyone with view_forms, a department means its people. */
    private function withinScope(User $user, DynamicForm $form): bool
    {
        return $form->department_id === null || $this->departments->canAccessDepartment($user, (int) $form->department_id);
    }
}
