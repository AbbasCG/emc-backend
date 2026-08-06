<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    /**
     * GET /api/admin/permissions
     *
     * Returns all permissions, optionally filtered by group.
     * Includes Arabic labels and a structured "modules" array for the role editor.
     */
    public function index(Request $request): JsonResponse
    {
        $permissions = Permission::query()
            ->when($request->group, fn ($q) => $q->where('group', $request->group))
            ->orderBy('group')
            ->orderBy('name')
            ->get(['id', 'name', 'display_name', 'label_ar', 'group', 'description', 'description_ar']);

        // Flat grouped by DB `group` column
        $grouped = $permissions->groupBy('group');

        // Structured module list for the frontend role editor
        $modules = $grouped->map(function ($perms, $groupKey) {
            return [
                'group'       => $groupKey,
                'group_label' => $this->groupLabel($groupKey),
                'permissions' => $perms->map(fn ($p) => [
                    'id'             => $p->id,
                    'key'            => $p->name,
                    'display_name'   => $p->display_name,
                    'label_ar'       => $p->label_ar,
                    'description'    => $p->description,
                    'description_ar' => $p->description_ar,
                ])->values(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'permissions' => $permissions,
                'grouped'     => $grouped,
                'modules'     => $modules,
                'total'       => $permissions->count(),
            ],
        ]);
    }

    /**
     * GET /api/admin/me/permissions
     *
     * Returns the current admin's own permission list with their role.
     */
    public function myPermissions(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data'    => [
                'user'        => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'role'        => $user->role,
                'permissions' => $user->allPermissions(),
            ],
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function groupLabel(string $group): string
    {
        return match ($group) {
            'users'             => 'المستخدمون',
            'education'         => 'التعليم والبرامج',
            'lms'               => 'نظام إدارة التعلم',
            'finance'           => 'المالية',
            'certificates'      => 'الشهادات',
            'operations'        => 'العمليات',
            'hr'                => 'الموارد البشرية',
            'partnerships'      => 'الشراكات',
            'marketing'         => 'التسويق والإعلام',
            'quality'           => 'الجودة والحوكمة',
            'support'           => 'الدعم الفني',
            'reports'           => 'التقارير والمؤشرات',
            'knowledge'         => 'قاعدة المعرفة',
            'ai'                => 'الذكاء الاصطناعي',
            'system'            => 'النظام والإعدادات',
            'workshop_requests' => 'طلبات البرامج التدريبية',
            'members'           => 'الأعضاء',
            'departments_g'     => 'الإدارات',
            'courses_g'         => 'الدورات',
            'attendance_g'      => 'الحضور',
            'assignments_g'     => 'الواجبات',
            default             => $group,
        };
    }
}
