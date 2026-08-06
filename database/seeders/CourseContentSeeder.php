<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseMaterial;
use App\Models\CourseModule;
use App\Models\CourseSession;
use Illuminate\Database\Seeder;

class CourseContentSeeder extends Seeder
{
    public function run(): void
    {
        $course = Course::first();

        if (! $course) {
            $this->command->warn('No courses found. Skipping CourseContentSeeder.');
            return;
        }

        // Module 1 — Introduction
        $module1 = CourseModule::create([
            'course_id'   => $course->id,
            'title'       => 'Introduction & Foundations',
            'description' => 'Overview of the course, objectives, and foundational concepts.',
            'sort_order'  => 1,
            'status'      => 'active',
            'is_active'   => true,
        ]);

        $session1 = CourseSession::create([
            'course_id'     => $course->id,
            'module_id'     => $module1->id,
            'title'         => 'Welcome & Course Overview',
            'description'   => 'Introduction to the instructor and course roadmap.',
            'location_type' => 'online',
            'meeting_link'  => 'https://zoom.us/j/example',
            'sort_order'    => 1,
            'status'        => 'published',
            'is_visible'    => true,
        ]);

        CourseMaterial::create([
            'course_id'         => $course->id,
            'module_id'         => $module1->id,
            'course_session_id' => $session1->id,
            'title'             => 'Course Syllabus',
            'description'       => 'Full syllabus with week-by-week breakdown.',
            'type'              => 'pdf',
            'external_url'      => null,
            'sort_order'        => 1,
            'is_visible'        => true,
        ]);

        CourseMaterial::create([
            'course_id'         => $course->id,
            'module_id'         => $module1->id,
            'course_session_id' => $session1->id,
            'title'             => 'Getting Started Guide',
            'description'       => 'How to navigate the platform and course materials.',
            'type'              => 'link',
            'external_url'      => 'https://example.com/getting-started',
            'sort_order'        => 2,
            'is_visible'        => true,
        ]);

        // Module 2 — Core Topics
        $module2 = CourseModule::create([
            'course_id'   => $course->id,
            'title'       => 'Core Concepts',
            'description' => 'Deep dive into the primary subject matter.',
            'sort_order'  => 2,
            'status'      => 'active',
            'is_active'   => true,
        ]);

        $session2 = CourseSession::create([
            'course_id'     => $course->id,
            'module_id'     => $module2->id,
            'title'         => 'Session 1 — Key Principles',
            'description'   => 'Exploring the fundamental principles of the subject.',
            'location_type' => 'online',
            'sort_order'    => 1,
            'status'        => 'published',
            'is_visible'    => true,
        ]);

        CourseMaterial::create([
            'course_id'         => $course->id,
            'module_id'         => $module2->id,
            'course_session_id' => $session2->id,
            'title'             => 'Reading Materials — Week 2',
            'type'              => 'pdf',
            'sort_order'        => 1,
            'is_visible'        => true,
        ]);

        CourseAssignment::create([
            'course_id'         => $course->id,
            'module_id'         => $module2->id,
            'course_session_id' => $session2->id,
            'title'             => 'Reflection Assignment',
            'description'       => 'Write a 500-word reflection on the key principles covered.',
            'instructions'      => "1. Review all reading materials.\n2. Write a 500-word reflection.\n3. Submit as a PDF.",
            'deadline'          => now()->addDays(7),
            'max_points'        => 20,
            'submission_type'   => 'file',
            'is_required'       => true,
            'is_visible'        => true,
            'status'            => 'published',
            'sort_order'        => 1,
        ]);

        // Module 3 — Assessment
        $module3 = CourseModule::create([
            'course_id'   => $course->id,
            'title'       => 'Assessment & Wrap-up',
            'description' => 'Final assignments and course completion requirements.',
            'sort_order'  => 3,
            'status'      => 'active',
            'is_active'   => true,
        ]);

        CourseAssignment::create([
            'course_id'    => $course->id,
            'module_id'    => $module3->id,
            'title'        => 'Final Project',
            'description'  => 'Apply everything learned in a capstone project.',
            'instructions' => "Submit a project that demonstrates mastery of the course topics.\nMinimum 10 pages or equivalent.",
            'deadline'     => now()->addDays(30),
            'max_points'   => 100,
            'submission_type' => 'file',
            'is_required'  => true,
            'is_visible'   => true,
            'status'       => 'published',
            'sort_order'   => 1,
        ]);

        $this->command->info("Course content seeded for course: {$course->title} (ID: {$course->id})");
    }
}
