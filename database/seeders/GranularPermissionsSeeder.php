<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * Seeds all granular permissions (dotted notation) with Arabic labels,
 * then assigns them to the appropriate roles via syncWithoutDetaching.
 *
 * Safe to re-run: uses updateOrCreate for permissions, syncWithoutDetaching for roles.
 * Does NOT remove any previously granted permissions.
 */
class GranularPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPermissions();
        $this->assignToRoles();
    }

    // ─── Permission definitions ───────────────────────────────────────────────

    private function seedPermissions(): void
    {
        $definitions = [

            // ── Workshop Requests ─────────────────────────────────────────────
            [
                'name'           => Permissions::WR_VIEW_OWN,
                'display_name'   => 'View Own Workshop Requests',
                'label_ar'       => 'عرض طلباتي',
                'group'          => 'workshop_requests',
                'description'    => 'See requests submitted by the user themselves.',
                'description_ar' => 'عرض الطلبات التي قدمها المستخدم نفسه',
            ],
            [
                'name'           => Permissions::WR_VIEW_DEPARTMENT,
                'display_name'   => 'View Department Workshop Requests',
                'label_ar'       => 'عرض طلبات الإدارة',
                'group'          => 'workshop_requests',
                'description'    => 'See requests assigned to the user\'s department stage.',
                'description_ar' => 'عرض الطلبات الخاصة بإدارة المستخدم',
            ],
            [
                'name'           => Permissions::WR_VIEW_ALL,
                'display_name'   => 'View All Workshop Requests',
                'label_ar'       => 'عرض كل الطلبات',
                'group'          => 'workshop_requests',
                'description'    => 'See all workshop requests regardless of department.',
                'description_ar' => 'عرض جميع طلبات البرامج بغض النظر عن الإدارة',
            ],
            [
                'name'           => Permissions::WR_CREATE,
                'display_name'   => 'Create Workshop Request',
                'label_ar'       => 'إنشاء طلب برنامج',
                'group'          => 'workshop_requests',
                'description'    => 'Submit a new workshop / training programme request.',
                'description_ar' => 'تقديم طلب برنامج تدريبي جديد',
            ],
            [
                'name'           => Permissions::WR_REVIEW,
                'display_name'   => 'Review Workshop Requests',
                'label_ar'       => 'مراجعة الطلبات',
                'group'          => 'workshop_requests',
                'description'    => 'Read and add notes to requests at the department stage.',
                'description_ar' => 'قراءة الطلبات وإضافة ملاحظات في مرحلة الإدارة',
            ],
            [
                'name'           => Permissions::WR_APPROVE,
                'display_name'   => 'Approve Workshop Requests',
                'label_ar'       => 'الموافقة على الطلبات',
                'group'          => 'workshop_requests',
                'description'    => 'Approve a workshop request at the current workflow stage.',
                'description_ar' => 'الموافقة على طلب البرنامج في المرحلة الحالية من سير العمل',
            ],
            [
                'name'           => Permissions::WR_REJECT,
                'display_name'   => 'Reject Workshop Requests',
                'label_ar'       => 'رفض الطلبات',
                'group'          => 'workshop_requests',
                'description'    => 'Reject a workshop request at the current workflow stage.',
                'description_ar' => 'رفض طلب البرنامج في المرحلة الحالية من سير العمل',
            ],
            [
                'name'           => Permissions::WR_ASSIGN,
                'display_name'   => 'Assign Workshop Requests',
                'label_ar'       => 'تعيين الطلبات',
                'group'          => 'workshop_requests',
                'description'    => 'Reassign or fast-track a workshop request.',
                'description_ar' => 'إعادة تعيين أو تسريع طلب البرنامج',
            ],
            [
                'name'           => Permissions::WR_EDIT,
                'display_name'   => 'Edit Workshop Requests',
                'label_ar'       => 'تعديل الطلبات',
                'group'          => 'workshop_requests',
                'description'    => 'Edit the content of an existing workshop request.',
                'description_ar' => 'تعديل محتوى طلب برنامج موجود',
            ],
            [
                'name'           => Permissions::WR_DELETE,
                'display_name'   => 'Delete Workshop Requests',
                'label_ar'       => 'حذف الطلبات',
                'group'          => 'workshop_requests',
                'description'    => 'Permanently delete a workshop request.',
                'description_ar' => 'حذف طلب البرنامج نهائياً',
            ],
            [
                'name'           => Permissions::WR_EXPORT,
                'display_name'   => 'Export Workshop Requests',
                'label_ar'       => 'تصدير الطلبات',
                'group'          => 'workshop_requests',
                'description'    => 'Export workshop requests to CSV / Excel.',
                'description_ar' => 'تصدير طلبات البرامج إلى CSV / Excel',
            ],
            [
                'name'           => Permissions::WR_MANAGE,
                'display_name'   => 'Manage All Workshop Requests',
                'label_ar'       => 'إدارة الطلبات كاملة',
                'group'          => 'workshop_requests',
                'description'    => 'Full control over all workshop requests (implies all WR permissions).',
                'description_ar' => 'صلاحية كاملة على جميع طلبات البرامج',
            ],

            // ── Members ───────────────────────────────────────────────────────
            [
                'name'           => Permissions::MEMBERS_VIEW,
                'display_name'   => 'View Members',
                'label_ar'       => 'عرض الأعضاء',
                'group'          => 'members',
                'description'    => 'View the member / team directory.',
                'description_ar' => 'عرض دليل الأعضاء والفريق',
            ],
            [
                'name'           => Permissions::MEMBERS_VIEW_DEPARTMENT,
                'display_name'   => 'View Department Members',
                'label_ar'       => 'عرض أعضاء الإدارة',
                'group'          => 'members',
                'description'    => 'View members within the user\'s own department.',
                'description_ar' => 'عرض أعضاء إدارة المستخدم فقط',
            ],
            [
                'name'           => Permissions::MEMBERS_CREATE,
                'display_name'   => 'Create Members',
                'label_ar'       => 'إضافة عضو',
                'group'          => 'members',
                'description'    => 'Add a new member profile.',
                'description_ar' => 'إضافة ملف عضو جديد',
            ],
            [
                'name'           => Permissions::MEMBERS_UPDATE,
                'display_name'   => 'Update Members',
                'label_ar'       => 'تعديل بيانات عضو',
                'group'          => 'members',
                'description'    => 'Edit an existing member\'s profile.',
                'description_ar' => 'تعديل بيانات عضو موجود',
            ],
            [
                'name'           => Permissions::MEMBERS_DELETE,
                'display_name'   => 'Delete Members',
                'label_ar'       => 'حذف عضو',
                'group'          => 'members',
                'description'    => 'Remove a member profile.',
                'description_ar' => 'حذف ملف عضو',
            ],
            [
                'name'           => Permissions::MEMBERS_RECOGNIZE,
                'display_name'   => 'Grant Member Recognition',
                'label_ar'       => 'منح تكريم',
                'group'          => 'members',
                'description'    => 'Award a recognition/badge to a member.',
                'description_ar' => 'منح تكريم أو شارة لعضو',
            ],
            [
                'name'           => Permissions::MEMBERS_MANAGE,
                'display_name'   => 'Manage All Members',
                'label_ar'       => 'إدارة الأعضاء كاملة',
                'group'          => 'members',
                'description'    => 'Full control over member profiles and recognitions.',
                'description_ar' => 'صلاحية كاملة على ملفات الأعضاء والتكريمات',
            ],

            // ── Departments ───────────────────────────────────────────────────
            [
                'name'           => Permissions::DEPTS_VIEW,
                'display_name'   => 'View Departments',
                'label_ar'       => 'عرض الإدارات',
                'group'          => 'departments_g',
                'description'    => 'View all departments in the organisation.',
                'description_ar' => 'عرض جميع الإدارات في المنظمة',
            ],
            [
                'name'           => Permissions::DEPTS_VIEW_OWN,
                'display_name'   => 'View Own Department',
                'label_ar'       => 'عرض إدارتي',
                'group'          => 'departments_g',
                'description'    => 'View the department the user manages.',
                'description_ar' => 'عرض الإدارة التي يديرها المستخدم',
            ],
            [
                'name'           => Permissions::DEPTS_UPDATE_OWN,
                'display_name'   => 'Update Own Department',
                'label_ar'       => 'تعديل إدارتي',
                'group'          => 'departments_g',
                'description'    => 'Edit settings/profile of the user\'s own department.',
                'description_ar' => 'تعديل إعدادات أو ملف الإدارة الخاصة بالمستخدم',
            ],
            [
                'name'           => Permissions::DEPTS_MANAGE,
                'display_name'   => 'Manage All Departments',
                'label_ar'       => 'إدارة الإدارات كاملة',
                'group'          => 'departments_g',
                'description'    => 'Full CRUD control over all departments.',
                'description_ar' => 'صلاحية كاملة على جميع الإدارات',
            ],

            // ── Courses ───────────────────────────────────────────────────────
            [
                'name'           => Permissions::COURSES_VIEW,
                'display_name'   => 'View Courses',
                'label_ar'       => 'عرض الدورات',
                'group'          => 'courses_g',
                'description'    => 'Browse and view course listings.',
                'description_ar' => 'تصفح وعرض قائمة الدورات',
            ],
            [
                'name'           => Permissions::COURSES_CREATE,
                'display_name'   => 'Create Courses',
                'label_ar'       => 'إنشاء دورة',
                'group'          => 'courses_g',
                'description'    => 'Create a new course.',
                'description_ar' => 'إنشاء دورة جديدة',
            ],
            [
                'name'           => Permissions::COURSES_UPDATE,
                'display_name'   => 'Update Courses',
                'label_ar'       => 'تعديل دورة',
                'group'          => 'courses_g',
                'description'    => 'Edit an existing course.',
                'description_ar' => 'تعديل دورة موجودة',
            ],
            [
                'name'           => Permissions::COURSES_DELETE,
                'display_name'   => 'Delete Courses',
                'label_ar'       => 'حذف دورة',
                'group'          => 'courses_g',
                'description'    => 'Delete a course from the system.',
                'description_ar' => 'حذف دورة من النظام',
            ],
            [
                'name'           => Permissions::COURSES_ASSIGN_INSTRUCTOR,
                'display_name'   => 'Assign Instructor to Course',
                'label_ar'       => 'تعيين محاضر للدورة',
                'group'          => 'courses_g',
                'description'    => 'Assign or change the instructor for a course.',
                'description_ar' => 'تعيين أو تغيير المحاضر للدورة',
            ],
            [
                'name'           => Permissions::COURSES_MANAGE,
                'display_name'   => 'Manage All Courses',
                'label_ar'       => 'إدارة الدورات كاملة',
                'group'          => 'courses_g',
                'description'    => 'Full control over courses (implies all COURSES permissions).',
                'description_ar' => 'صلاحية كاملة على جميع الدورات',
            ],

            // ── Attendance ────────────────────────────────────────────────────
            [
                'name'           => Permissions::ATTENDANCE_VIEW,
                'display_name'   => 'View Attendance',
                'label_ar'       => 'عرض سجلات الحضور',
                'group'          => 'attendance_g',
                'description'    => 'View attendance records for sessions.',
                'description_ar' => 'عرض سجلات حضور الجلسات',
            ],
            [
                'name'           => Permissions::ATTENDANCE_MARK,
                'display_name'   => 'Mark Attendance',
                'label_ar'       => 'تسجيل الحضور',
                'group'          => 'attendance_g',
                'description'    => 'Mark students as present / absent / late / excused.',
                'description_ar' => 'تسجيل حضور الطلاب (حاضر / غائب / متأخر / معذور)',
            ],
            [
                'name'           => Permissions::ATTENDANCE_UPDATE,
                'display_name'   => 'Update Attendance Records',
                'label_ar'       => 'تعديل سجل الحضور',
                'group'          => 'attendance_g',
                'description'    => 'Correct or override previously saved attendance.',
                'description_ar' => 'تصحيح أو تعديل سجل الحضور المحفوظ مسبقاً',
            ],
            [
                'name'           => Permissions::ATTENDANCE_MANAGE,
                'display_name'   => 'Manage Attendance',
                'label_ar'       => 'إدارة الحضور كاملة',
                'group'          => 'attendance_g',
                'description'    => 'Full control over all attendance records.',
                'description_ar' => 'صلاحية كاملة على جميع سجلات الحضور',
            ],

            // ── Assignments ───────────────────────────────────────────────────
            [
                'name'           => Permissions::ASSIGNMENTS_VIEW,
                'display_name'   => 'View Assignments',
                'label_ar'       => 'عرض الواجبات',
                'group'          => 'assignments_g',
                'description'    => 'View assignments and their submissions.',
                'description_ar' => 'عرض الواجبات وتسليماتها',
            ],
            [
                'name'           => Permissions::ASSIGNMENTS_CREATE,
                'display_name'   => 'Create Assignments',
                'label_ar'       => 'إنشاء واجب',
                'group'          => 'assignments_g',
                'description'    => 'Create a new assignment for a course or session.',
                'description_ar' => 'إنشاء واجب جديد لدورة أو جلسة',
            ],
            [
                'name'           => Permissions::ASSIGNMENTS_UPDATE,
                'display_name'   => 'Update Assignments',
                'label_ar'       => 'تعديل واجب',
                'group'          => 'assignments_g',
                'description'    => 'Edit an existing assignment.',
                'description_ar' => 'تعديل واجب موجود',
            ],
            [
                'name'           => Permissions::ASSIGNMENTS_DELETE,
                'display_name'   => 'Delete Assignments',
                'label_ar'       => 'حذف واجب',
                'group'          => 'assignments_g',
                'description'    => 'Delete an assignment.',
                'description_ar' => 'حذف واجب من النظام',
            ],
            [
                'name'           => Permissions::ASSIGNMENTS_REVIEW,
                'display_name'   => 'Review Assignment Submissions',
                'label_ar'       => 'مراجعة تسليمات الواجبات',
                'group'          => 'assignments_g',
                'description'    => 'Grade and provide feedback on student submissions.',
                'description_ar' => 'تصحيح تسليمات الطلاب وإعطاء التغذية الراجعة',
            ],
            [
                'name'           => Permissions::ASSIGNMENTS_MANAGE,
                'display_name'   => 'Manage All Assignments',
                'label_ar'       => 'إدارة الواجبات كاملة',
                'group'          => 'assignments_g',
                'description'    => 'Full control over all assignments and submissions.',
                'description_ar' => 'صلاحية كاملة على جميع الواجبات والتسليمات',
            ],
        ];

        foreach ($definitions as $def) {
            Permission::updateOrCreate(['name' => $def['name']], $def);
        }

        Permission::whereNull('label_ar')->orWhere('label_ar', '')->get()->each(function ($p) {
            $p->update(['label_ar' => $p->display_name ?: $p->name]);
        });
    }

    // ─── Role assignments ─────────────────────────────────────────────────────

    private function assignToRoles(): void
    {
        $matrix = [

            'super_admin' => Permissions::all(),

            'admin' => [
                Permissions::WR_VIEW_ALL, Permissions::WR_MANAGE,
                Permissions::WR_ASSIGN, Permissions::WR_EDIT, Permissions::WR_DELETE, Permissions::WR_EXPORT,
                Permissions::WR_REVIEW, Permissions::WR_APPROVE, Permissions::WR_REJECT,
                Permissions::MEMBERS_MANAGE,
                Permissions::DEPTS_MANAGE, Permissions::DEPTS_VIEW,
                Permissions::COURSES_MANAGE, Permissions::COURSES_VIEW,
                Permissions::COURSES_CREATE, Permissions::COURSES_UPDATE,
                Permissions::COURSES_DELETE, Permissions::COURSES_ASSIGN_INSTRUCTOR,
                Permissions::ATTENDANCE_MANAGE, Permissions::ATTENDANCE_VIEW,
                Permissions::ASSIGNMENTS_MANAGE, Permissions::ASSIGNMENTS_VIEW,
            ],

            'executive_admin' => [
                Permissions::WR_VIEW_ALL, Permissions::WR_APPROVE, Permissions::WR_ASSIGN,
                Permissions::MEMBERS_VIEW,
                Permissions::DEPTS_VIEW,
                Permissions::COURSES_VIEW,
                Permissions::ATTENDANCE_VIEW,
                Permissions::ASSIGNMENTS_VIEW,
            ],

            'quality_manager' => [
                Permissions::WR_VIEW_DEPARTMENT, Permissions::WR_REVIEW,
                Permissions::WR_APPROVE, Permissions::WR_REJECT,
                Permissions::MEMBERS_VIEW, Permissions::DEPTS_VIEW,
            ],

            'department_manager' => [
                Permissions::WR_VIEW_DEPARTMENT, Permissions::WR_REVIEW,
                Permissions::WR_APPROVE, Permissions::WR_REJECT,
                Permissions::DEPTS_VIEW, Permissions::DEPTS_VIEW_OWN, Permissions::DEPTS_UPDATE_OWN,
                Permissions::MEMBERS_VIEW, Permissions::MEMBERS_VIEW_DEPARTMENT, Permissions::MEMBERS_RECOGNIZE,
                Permissions::COURSES_VIEW,
                Permissions::ATTENDANCE_VIEW,
                Permissions::ASSIGNMENTS_VIEW,
            ],

            'finance_manager' => [
                Permissions::WR_VIEW_DEPARTMENT, Permissions::WR_REVIEW,
                Permissions::WR_APPROVE, Permissions::WR_REJECT,
                Permissions::MEMBERS_VIEW,
            ],

            'marketing_manager' => [
                Permissions::WR_VIEW_DEPARTMENT, Permissions::WR_REVIEW,
                Permissions::WR_APPROVE, Permissions::WR_REJECT,
                Permissions::MEMBERS_VIEW,
            ],

            'operations_manager' => [
                Permissions::WR_VIEW_DEPARTMENT, Permissions::WR_REVIEW,
                Permissions::WR_APPROVE, Permissions::WR_REJECT,
                Permissions::MEMBERS_VIEW, Permissions::DEPTS_VIEW,
                Permissions::COURSES_VIEW, Permissions::ATTENDANCE_VIEW, Permissions::ASSIGNMENTS_VIEW,
            ],

            'programs_manager' => [
                Permissions::WR_VIEW_DEPARTMENT, Permissions::WR_REVIEW,
                Permissions::WR_APPROVE, Permissions::WR_REJECT,
                Permissions::MEMBERS_VIEW,
                Permissions::COURSES_VIEW, Permissions::COURSES_CREATE, Permissions::COURSES_UPDATE,
                Permissions::ATTENDANCE_VIEW, Permissions::ASSIGNMENTS_VIEW,
            ],

            'instructor' => [
                Permissions::ATTENDANCE_VIEW, Permissions::ATTENDANCE_MARK, Permissions::ATTENDANCE_UPDATE,
                Permissions::ASSIGNMENTS_VIEW, Permissions::ASSIGNMENTS_CREATE, Permissions::ASSIGNMENTS_REVIEW,
                Permissions::COURSES_VIEW,
            ],

            'student' => [
                Permissions::WR_VIEW_OWN, Permissions::WR_CREATE,
                Permissions::COURSES_VIEW, Permissions::ATTENDANCE_VIEW, Permissions::ASSIGNMENTS_VIEW,
                Permissions::MEMBERS_VIEW,
            ],

            'hr_manager' => [
                Permissions::MEMBERS_VIEW, Permissions::MEMBERS_CREATE,
                Permissions::MEMBERS_UPDATE, Permissions::MEMBERS_RECOGNIZE,
                Permissions::DEPTS_VIEW,
            ],
        ];

        foreach ($matrix as $roleName => $permNames) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }

            // Resolve permission IDs (filter out any that don't exist yet)
            $ids = Permission::whereIn('name', array_unique($permNames))->pluck('id');
            $role->permissions()->syncWithoutDetaching($ids);
        }
    }
}
