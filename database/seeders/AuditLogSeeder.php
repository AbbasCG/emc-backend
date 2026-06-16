<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seed demo audit log entries.
 * Only for local/dev environments — NOT run in production by default.
 */
class AuditLogSeeder extends Seeder
{
    public function run(): void
    {
        $admin   = User::where('role', 'super_admin')->first();
        $student = User::where('role', 'student')->first();

        $actor = $admin ?? User::first();
        if (! $actor) {
            $this->command->warn('No users found — skipping AuditLogSeeder.');
            return;
        }

        $entries = [
            [
                'user_id'    => $actor->id,
                'user_name'  => $actor->name,
                'user_email' => $actor->email,
                'user_role'  => $actor->role,
                'action'     => 'CREATE',
                'entity_type' => 'Course',
                'entity_id'   => 1,
                'entity_name' => 'Python Basics',
                'description' => $actor->name . ' created course "Python Basics"',
                'new_values'  => ['title' => 'Python Basics', 'status' => 'draft', 'price' => 220],
                'ip_address'  => '127.0.0.1',
                'method'      => 'POST',
                'route'       => 'admin.courses.store',
            ],
            [
                'user_id'    => $actor->id,
                'user_name'  => $actor->name,
                'user_email' => $actor->email,
                'user_role'  => $actor->role,
                'action'     => 'UPDATE',
                'entity_type' => 'Course',
                'entity_id'   => 1,
                'entity_name' => 'Python Basics',
                'description' => $actor->name . ' updated course "Python Basics"',
                'old_values'  => ['price' => 220],
                'new_values'  => ['price' => 169],
                'changed_fields' => ['price'],
                'ip_address'  => '127.0.0.1',
                'method'      => 'PATCH',
                'route'       => 'admin.courses.update',
            ],
            [
                'user_id'    => $actor->id,
                'user_name'  => $actor->name,
                'user_email' => $actor->email,
                'user_role'  => $actor->role,
                'action'     => 'PUBLISH',
                'entity_type' => 'Course',
                'entity_id'   => 1,
                'entity_name' => 'Python Basics',
                'description' => $actor->name . ' published course "Python Basics"',
                'old_values'  => ['status' => 'draft'],
                'new_values'  => ['status' => 'published'],
                'changed_fields' => ['status'],
                'ip_address'  => '127.0.0.1',
                'method'      => 'PATCH',
            ],
            [
                'user_id'    => $actor->id,
                'user_name'  => $actor->name,
                'user_email' => $actor->email,
                'user_role'  => $actor->role,
                'action'     => 'LOGIN',
                'entity_type' => 'User',
                'entity_id'   => $actor->id,
                'entity_name' => $actor->name,
                'description' => $actor->name . ' logged in',
                'ip_address'  => '127.0.0.1',
                'method'      => 'POST',
                'route'       => 'auth.login',
            ],
            [
                'user_id'    => $student?->id ?? $actor->id,
                'user_name'  => $student?->name ?? $actor->name,
                'user_email' => $student?->email ?? $actor->email,
                'user_role'  => $student?->role ?? 'student',
                'action'     => 'ENROLL',
                'entity_type' => 'Course',
                'entity_id'   => 1,
                'entity_name' => 'Python Basics',
                'description' => ($student?->name ?? 'Student') . ' enrolled in course "Python Basics"',
                'new_values'  => ['status' => 'registered'],
                'ip_address'  => '127.0.0.1',
                'method'      => 'POST',
            ],
            [
                'user_id'    => $actor->id,
                'user_name'  => $actor->name,
                'user_email' => $actor->email,
                'user_role'  => $actor->role,
                'action'     => 'APPROVE',
                'entity_type' => 'WorkshopRequest',
                'entity_id'   => 1,
                'entity_name' => 'AI Workshop',
                'description' => $actor->name . ' approved workshop request "AI Workshop"',
                'ip_address'  => '127.0.0.1',
                'method'      => 'POST',
            ],
            [
                'user_id'    => $actor->id,
                'user_name'  => $actor->name,
                'user_email' => $actor->email,
                'user_role'  => $actor->role,
                'action'     => 'REJECT',
                'entity_type' => 'VolunteerRequest',
                'entity_id'   => 1,
                'entity_name' => 'John Doe',
                'description' => $actor->name . ' rejected volunteer request from "John Doe"',
                'old_values'  => ['status' => 'pending'],
                'new_values'  => ['status' => 'rejected'],
                'changed_fields' => ['status'],
                'ip_address'  => '127.0.0.1',
                'method'      => 'PATCH',
            ],
            [
                'user_id'    => $actor->id,
                'user_name'  => $actor->name,
                'user_email' => $actor->email,
                'user_role'  => $actor->role,
                'action'     => 'EXPORT',
                'entity_type' => 'course_report',
                'description' => $actor->name . ' exported course_report',
                'ip_address'  => '127.0.0.1',
                'method'      => 'POST',
            ],
            [
                'user_id'    => $student?->id ?? $actor->id,
                'user_name'  => $student?->name ?? $actor->name,
                'user_email' => $student?->email ?? $actor->email,
                'user_role'  => $student?->role ?? 'student',
                'action'     => 'DOWNLOAD',
                'entity_type' => 'CourseMaterial',
                'entity_id'   => 1,
                'entity_name' => 'lecture-01.pdf',
                'description' => 'Downloaded "lecture-01.pdf"',
                'ip_address'  => '127.0.0.1',
                'method'      => 'GET',
            ],
            [
                'user_id'    => $actor->id,
                'user_name'  => $actor->name,
                'user_email' => $actor->email,
                'user_role'  => $actor->role,
                'action'     => 'DELETE',
                'entity_type' => 'Course',
                'entity_id'   => 99,
                'entity_name' => 'Old Test Course',
                'description' => $actor->name . ' deleted course "Old Test Course"',
                'old_values'  => ['title' => 'Old Test Course', 'status' => 'draft'],
                'ip_address'  => '127.0.0.1',
                'method'      => 'DELETE',
            ],
        ];

        foreach ($entries as $entry) {
            AuditLog::create($entry);
        }

        $this->command->info('AuditLogSeeder: created ' . count($entries) . ' demo audit log entries.');
    }
}
