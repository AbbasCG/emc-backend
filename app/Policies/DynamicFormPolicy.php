<?php

namespace App\Policies;

use App\Models\DynamicForm;
use App\Models\User;
use App\Services\Forms\FormAccessService;

/**
 * Thin Gate adapter over FormAccessService. super_admin and tech_admin never reach these methods (Gate::before).
 */
class DynamicFormPolicy
{
    public function __construct(private readonly FormAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canViewAny($user);
    }

    public function view(User $user, DynamicForm $form): bool
    {
        return $this->access->canView($user, $form);
    }

    public function create(User $user): bool
    {
        return $this->access->canManageAny($user);
    }

    public function update(User $user, DynamicForm $form): bool
    {
        return $this->access->canManage($user, $form);
    }

    /** Publishing, closing, reopening, unpublishing and archiving. */
    public function publish(User $user, DynamicForm $form): bool
    {
        return $this->access->canManage($user, $form);
    }

    /** Restoring an older version into the draft. */
    public function restore(User $user, DynamicForm $form): bool
    {
        return $this->access->canManage($user, $form);
    }

    /** Collaborators, ownership, visibility. */
    public function manageAccess(User $user, DynamicForm $form): bool
    {
        return $this->access->canManageAccess($user, $form);
    }

    /** The Share screen: links, access mode and invitations are visible to those who can edit the form. */
    public function viewSharing(User $user, DynamicForm $form): bool
    {
        return $this->access->canManage($user, $form) || $this->access->canManageAccess($user, $form);
    }

    /** Creating or revoking links, changing the access mode and inviting: the owner and platform admins only. */
    public function manageSharing(User $user, DynamicForm $form): bool
    {
        return $this->access->canManageAccess($user, $form);
    }

    public function delete(User $user, DynamicForm $form): bool
    {
        return $this->access->canManageAccess($user, $form);
    }

    /** The history and the versions describe the form, not its responses: whoever can open the form can read them. */
    public function viewActivity(User $user, DynamicForm $form): bool
    {
        return $this->access->canView($user, $form);
    }

    public function viewVersions(User $user, DynamicForm $form): bool
    {
        return $this->access->canView($user, $form);
    }

    /** Reading the responses (and their attachments) is its own question: see FormAccessService. */
    public function viewResponses(User $user, DynamicForm $form): bool
    {
        return $this->access->canViewResponses($user, $form);
    }

    /** Correcting a submitted answer: an explicit grant, separate from reading the responses. */
    public function correctResponses(User $user, DynamicForm $form): bool
    {
        return $this->access->canCorrectResponses($user, $form);
    }

    public function manageResponses(User $user, DynamicForm $form): bool
    {
        return $this->access->canManageResponses($user, $form);
    }
}
