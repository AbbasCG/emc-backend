<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\PermissionNode;
use Illuminate\Database\Seeder;

class PermissionNodeSeeder extends Seeder
{
    public function run(): void
    {
        $groupLabels = [
            'workshop_requests' => ['طلب البرامج والورش', 'Workshop Requests'],
            'members'           => ['إدارة الأعضاء والفريق', 'Team & Members'],
            'departments_g'     => ['إدارة الإدارات والأقسام', 'Departments & Sections'],
            'courses_g'         => ['إدارة الدورات والمسارات', 'Courses & Learning Paths'],
            'attendance_g'      => ['إدارة الحضور والغياب', 'Attendance & Logs'],
            'assignments_g'     => ['إدارة الواجبات والتسليمات', 'Assignments & Submissions'],
            'finance'           => ['الشؤون المالية والاعتمادات', 'Finance & Approvals'],
            'tickets'           => ['الدعم الفني والتذاكر', 'Support & Tickets'],
            'tasks'             => ['المهام والعمليات', 'Tasks & Operations'],
        ];

        $permissions = Permission::all();
        $grouped = $permissions->groupBy('group');

        $sortOrder = 1;

        foreach ($grouped as $groupName => $groupPermissions) {
            [$labelAr, $labelEn] = $groupLabels[$groupName] ?? [ucfirst(str_replace('_', ' ', $groupName)), ucfirst($groupName)];

            $sectionNode = PermissionNode::updateOrCreate(
                ['key' => 'section.' . $groupName],
                [
                    'parent_id'       => null,
                    'node_type'       => 'section',
                    'slug'            => 'section-' . $groupName,
                    'label_ar'        => $labelAr,
                    'label_en'        => $labelEn,
                    'permission_name' => null,
                    'sort_order'      => $sortOrder++,
                    'is_active'       => true,
                    'is_system'       => false,
                ]
            );

            foreach ($groupPermissions as $perm) {
                PermissionNode::updateOrCreate(
                    ['key' => $perm->name],
                    [
                        'parent_id'       => $sectionNode->id,
                        'node_type'       => 'action',
                        'slug'            => str_replace(['.', '_'], '-', $perm->name),
                        'label_ar'        => $perm->label_ar ?: $perm->display_name ?: $perm->name,
                        'label_en'        => $perm->display_name,
                        'permission_name' => $perm->name,
                        'sort_order'      => $sortOrder++,
                        'is_active'       => true,
                        'is_system'       => false,
                    ]
                );
            }
        }
    }
}
