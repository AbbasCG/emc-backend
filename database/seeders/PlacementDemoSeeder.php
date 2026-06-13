<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Instructor;
use App\Models\OralAssessmentSlot;
use App\Models\PlacementTest;
use App\Models\Registration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the full English placement test demo environment:
 *
 *  1. Instructor account  → english.instructor@emc.test / password
 *  2. Instructor profile  → linked to that user
 *  3. Student account     → placement.student@emc.test  / password
 *  4. Test course         → english-placement-test-course (requires_placement_test = true)
 *  5. Student enrollment  → status = Registered
 *  6. Oral slots          → 10:00, 11:00, 18:00 for each of the next 7 days
 *  7. Placement test      → delegated to PlacementTestSeeder (70 questions)
 *
 * The seeder is fully idempotent — safe to run multiple times.
 */
class PlacementDemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Ensure placement test exists ───────────────────────────────────
        $this->call(PlacementTestSeeder::class);
        $test = PlacementTest::where('is_active', true)->latest()->first();

        // ── 2. Instructor user + profile ──────────────────────────────────────
        $instructorUser = User::updateOrCreate(
            ['email' => 'english.instructor@emc.test'],
            [
                'name'     => 'English Instructor',
                'password' => Hash::make('password'),
                'role'     => 'instructor',
                'is_active'=> true,
            ]
        );

        $instructorProfile = Instructor::updateOrCreate(
            ['user_id' => $instructorUser->id],
            [
                'name'  => 'English Instructor',
                'title' => 'Senior English Language Instructor',
                'bio'   => 'Specialises in CEFR assessment and English language placement testing.',
            ]
        );

        // ── 3. Student user ───────────────────────────────────────────────────
        $student = User::updateOrCreate(
            ['email' => 'placement.student@emc.test'],
            [
                'name'     => 'Placement Test Student',
                'password' => Hash::make('password'),
                'role'     => 'student',
                'is_active'=> true,
            ]
        );

        // ── 4. Test course ────────────────────────────────────────────────────
        $course = Course::updateOrCreate(
            ['slug' => 'english-placement-test-course'],
            [
                'title'                  => 'English Placement Test Course',
                'short_description'      => 'دورة اللغة الإنجليزية - تحديد المستوى',
                'description'            => 'A structured English language course that begins with a comprehensive placement test to determine the student\'s current level (Beginner through Advanced). Students complete a 70-question written diagnostic followed by an oral assessment interview before accessing course materials.',
                'type'                   => 'free',
                'program_type'           => 'course',
                'session_format'         => 'online',
                'status'                 => 'published',
                'language'               => 'أنجليزي',
                'level'                  => 'مبتدئ',
                'certificate_type'       => 'شهادة إتمام',
                'is_free'                => true,
                'is_online'              => true,
                'registration_open'      => true,
                'registration_status'    => 'open',
                'instructor_id'          => $instructorProfile->id,
                'requires_placement_test'=> true,
                'placement_test_type'    => 'english_cefr',
                'training_hours'         => 60,
                'capacity'               => 30,
                'notes'                  => 'Demo course for English placement test flow testing.',
                'course_image'           => 'https://placehold.co/1200x675/2563eb/ffffff?text=English+Course',
                'course_thumbnail'       => 'https://placehold.co/400x225/2563eb/ffffff?text=English+Course',
            ]
        );

        // ── 4b. Example workshop (no placement test, no course-only fields) ──────
        $workshop = Course::updateOrCreate(
            ['slug' => 'example-workshop-demo'],
            [
                'title'                  => 'مثال على ورشة عمل',
                'short_description'      => 'ورشة عمل تجريبية للتحقق من صحة النموذج',
                'description'            => 'ورشة عمل مثال لاختبار أن الحقول الخاصة بالدورات (مثل training_hours وlevel) ليست مطلوبة للورش.',
                'type'                   => 'free',
                'program_type'           => 'workshop',
                'session_format'         => 'workshop_single_session',
                'status'                 => 'published',
                'language'               => 'عربي',
                'is_free'                => true,
                'is_online'              => true,
                'registration_open'      => true,
                'registration_status'    => 'open',
                'requires_placement_test'=> false,
                'certificate_type'       => 'شهادة حضور',
                'capacity'               => 50,
                'notes'                  => 'Demo workshop — level and training_hours intentionally left null.',
                'course_image'           => 'https://placehold.co/1200x675/16a34a/ffffff?text=Workshop',
                'course_thumbnail'       => 'https://placehold.co/400x225/16a34a/ffffff?text=Workshop',
            ]
        );

        // ── 4c. Regular course without placement test ─────────────────────────
        $regularCourse = Course::updateOrCreate(
            ['slug' => 'digital-skills-no-placement'],
            [
                'title'                  => 'Digital Skills Fundamentals',
                'short_description'      => 'دورة المهارات الرقمية الأساسية',
                'description'            => 'A practical course covering core digital skills including computer literacy, internet usage, and productivity software. No placement test required — open to all levels.',
                'type'                   => 'free',
                'program_type'           => 'course',
                'session_format'         => 'online',
                'status'                 => 'published',
                'language'               => 'عربي',
                'level'                  => 'مبتدئ',
                'certificate_type'       => 'شهادة إتمام',
                'is_free'                => true,
                'is_online'              => true,
                'registration_open'      => true,
                'registration_status'    => 'open',
                'requires_placement_test'=> false,
                'training_hours'         => 20,
                'capacity'               => 100,
                'notes'                  => 'Demo course with requires_placement_test = false.',
                'course_image'           => 'https://placehold.co/1200x675/dc2626/ffffff?text=Digital+Skills',
                'course_thumbnail'       => 'https://placehold.co/400x225/dc2626/ffffff?text=Digital+Skills',
            ]
        );

        // ── 5. Enroll student ─────────────────────────────────────────────────
        Registration::updateOrCreate(
            [
                'user_id'   => $student->id,
                'course_id' => $course->id,
            ],
            [
                'full_name' => $student->name,
                'email'     => $student->email,
                'phone'     => '0500000000',
                'status'    => 'Registered',
            ]
        );

        // ── 6. Oral assessment slots (next 7 days) ────────────────────────────
        // Always delete unbooked slots and recreate fresh ones so re-runs
        // never leave only expired slots in the database.
        OralAssessmentSlot::where('course_id', $course->id)
            ->where('is_booked', false)
            ->delete();

        $slotTimes = [
            ['10:00', '10:30'],
            ['11:00', '11:30'],
            ['18:00', '18:30'],
        ];

        for ($day = 1; $day <= 7; $day++) {
            $date = Carbon::today()->addDays($day);

            // Skip Friday (5) and Saturday (6) — EMC weekend
            if (in_array($date->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY])) {
                continue;
            }

            foreach ($slotTimes as [$start, $end]) {
                OralAssessmentSlot::create([
                    'course_id'      => $course->id,
                    'instructor_id'  => $instructorProfile->id,
                    'starts_at'      => Carbon::parse($date->toDateString() . ' ' . $start),
                    'ends_at'        => Carbon::parse($date->toDateString() . ' ' . $end),
                    'booking_status' => 'available',
                    'is_booked'      => false,
                ]);
            }
        }

        // ── Output summary ────────────────────────────────────────────────────
        $slotCount = OralAssessmentSlot::where('course_id', $course->id)
            ->where('booking_status', 'available')
            ->count();

        $this->command->info('');
        $this->command->info('  ✓ Placement test : ' . ($test ? $test->title : 'not found'));
        $this->command->info('  ✓ Instructor     : ' . $instructorUser->email . ' (ID ' . $instructorProfile->id . ')');
        $this->command->info('  ✓ Student        : ' . $student->email . ' (ID ' . $student->id . ')');
        $this->command->info('  ✓ Course (placement=true)  : ' . $course->title . ' (ID ' . $course->id . ')');
        $this->command->info('  ✓ Course (placement=false) : ' . $regularCourse->title . ' (ID ' . $regularCourse->id . ')');
        $this->command->info('  ✓ Workshop                 : ' . $workshop->title . ' (ID ' . $workshop->id . ')');
        $this->command->info('  ✓ Enrollment     : student registered in course');
        $this->command->info('  ✓ Oral slots     : ' . $slotCount . ' available slot(s) for this course');
        $this->command->info('');
    }
}
