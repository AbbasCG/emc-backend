<?php

namespace App\Livewire;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionAssignment;
use Livewire\Component;

class UserPermissionsTransfer extends Component
{
    public int $userId;

    public string $searchUnassigned = '';
    public string $searchAssigned = '';

    public array $selectedUnassigned = [];
    public array $selectedAssigned = [];

    public function mount(int $userId): void
    {
        $this->userId = $userId;
    }

    public function getUserProperty(): User
    {
        return User::findOrFail($this->userId);
    }

    public function grantPermission(int $permissionId): void
    {
        UserPermissionAssignment::updateOrCreate(
            [
                'user_id' => $this->userId,
                'permission_id' => $permissionId,
            ],
            [
                'state' => 'allow',
                'granted_by' => auth()->id(),
            ]
        );

        $this->user->flushPermissionCache();
        $this->selectedUnassigned = [];
    }

    public function revokePermission(int $permissionId): void
    {
        UserPermissionAssignment::where('user_id', $this->userId)
            ->where('permission_id', $permissionId)
            ->delete();

        $this->user->flushPermissionCache();
        $this->selectedAssigned = [];
    }

    public function grantSelected(array $ids = []): void
    {
        $targetIds = !empty($ids) ? $ids : $this->selectedUnassigned;

        foreach ($targetIds as $permId) {
            if ($permId) {
                UserPermissionAssignment::updateOrCreate(
                    [
                        'user_id' => $this->userId,
                        'permission_id' => (int) $permId,
                    ],
                    [
                        'state' => 'allow',
                        'granted_by' => auth()->id(),
                    ]
                );
            }
        }

        $this->user->flushPermissionCache();
        $this->selectedUnassigned = [];
    }

    public function revokeSelected(array $ids = []): void
    {
        $targetIds = !empty($ids) ? $ids : $this->selectedAssigned;

        if (!empty($targetIds)) {
            UserPermissionAssignment::where('user_id', $this->userId)
                ->whereIn('permission_id', array_map('intval', $targetIds))
                ->delete();
        }

        $this->user->flushPermissionCache();
        $this->selectedAssigned = [];
    }

    public function grantAll(): void
    {
        $allUnassigned = $this->getUnassignedPermissionsProperty();
        foreach ($allUnassigned as $p) {
            $this->grantPermission($p->id);
        }
        $this->selectedUnassigned = [];
    }

    public function revokeAll(): void
    {
        $allAssigned = $this->getAssignedPermissionsProperty();
        foreach ($allAssigned as $p) {
            $this->revokePermission($p->id);
        }
        $this->selectedAssigned = [];
    }

    public function getAssignedPermissionsProperty()
    {
        $assignedIds = UserPermissionAssignment::where('user_id', $this->userId)
            ->where('state', 'allow')
            ->pluck('permission_id')
            ->toArray();

        $query = Permission::query()->whereIn('id', $assignedIds);

        if (trim($this->searchAssigned) !== '') {
            $s = trim($this->searchAssigned);
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('label_ar', 'like', "%{$s}%")
                  ->orWhere('group', 'like', "%{$s}%");
            });
        }

        return $query->orderBy('group')->orderBy('label_ar')->get();
    }

    public function getUnassignedPermissionsProperty()
    {
        $assignedIds = UserPermissionAssignment::where('user_id', $this->userId)
            ->where('state', 'allow')
            ->pluck('permission_id')
            ->toArray();

        $query = Permission::query()->whereNotIn('id', $assignedIds);

        if (trim($this->searchUnassigned) !== '') {
            $s = trim($this->searchUnassigned);
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('label_ar', 'like', "%{$s}%")
                  ->orWhere('group', 'like', "%{$s}%");
            });
        }

        return $query->orderBy('group')->orderBy('label_ar')->get();
    }

    public function render()
    {
        return view('livewire.user-permissions-transfer', [
            'assignedPermissions' => $this->assignedPermissions,
            'unassignedPermissions' => $this->unassignedPermissions,
        ]);
    }
}
