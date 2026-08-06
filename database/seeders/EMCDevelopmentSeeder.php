<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseMaterial;
use App\Models\CourseModule;
use App\Models\CourseSession;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Local/testing-only development seed data.
 * Never runs in production (DatabaseSeeder guards the call).
 */
class EMCDevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedExtraInstructorUsers();
        $this->updateDemoUserDetails();
        $this->seedInstructors();
        [$c1, $c2, $c3, $c4, $c5, $c6] = $this->seedCourses();
        $this->seedRegistrations($c1, $c2, $c3, $c5);
        $this->seedLmsContent($c1, $c2);
        $this->seedNotifications($c1, $c2);
        $this->seedStudentProgress($c1, $c3);

        $this->command->info('✓ EMC development seed data ready.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function seedExtraInstructorUsers(): void
    {
        $extras = [
            ['email' => 'instructor2@emc.test', 'name' => 'أ. سارة العلي',      'role' => 'instructor'],
            ['email' => 'instructor3@emc.test', 'name' => 'أ. عباس عبدالله',    'role' => 'instructor'],
        ];

        foreach ($extras as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name'      => $data['name'],
                    'password'  => Hash::make('password'),
                    'role'      => $data['role'],
                    'is_active' => true,
                    'phone'     => '+31600000001',
                    'city'      => 'Breda',
                    'country'   => 'NL',
                ],
            );
        }
    }

    private function updateDemoUserDetails(): void
    {
        $details = [
            'superadmin@emc.test' => ['phone' => '+31600000010', 'city' => 'Amsterdam', 'country' => 'NL'],
            'admin@emc.test'      => ['phone' => '+31600000011', 'city' => 'Rotterdam', 'country' => 'NL'],
            'instructor@emc.test' => ['phone' => '+31600000012', 'city' => 'Utrecht',   'country' => 'NL'],
            'student@emc.test'    => ['phone' => '+31600000013', 'city' => 'Breda',     'country' => 'NL'],
            'finance@emc.test'    => ['phone' => '+31600000014', 'city' => 'Den Haag',  'country' => 'NL'],
            'hr@emc.test'         => ['phone' => '+31600000015', 'city' => 'Tilburg',   'country' => 'NL'],
            'support@emc.test'    => ['phone' => '+31600000016', 'city' => 'Eindhoven', 'country' => 'NL'],
        ];

        foreach ($details as $email => $patch) {
            User::where('email', $email)->update($patch);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function seedInstructors(): void
    {
        $map = [
            'instructor@emc.test'  => [
                'name'  => 'أ. أحمد الحربي',
                'title' => 'خبير الذكاء الاصطناعي والتحول الرقمي',
                'bio'   => 'مدرب متخصص في الذكاء الاصطناعي وتقنياته التطبيقية، يمتلك خبرة تزيد على عشر سنوات في التدريب التقني.',
            ],
            'instructor2@emc.test' => [
                'name'  => 'أ. سارة العلي',
                'title' => 'مدربة تطوير المهارات المهنية',
                'bio'   => 'متخصصة في تطوير المهارات المهنية وكتابة السيرة الذاتية وإعداد المتدربين لسوق العمل.',
            ],
            'instructor3@emc.test' => [
                'name'  => 'أ. عباس عبدالله',
                'title' => 'مطور ويب ومدرب تقني',
                'bio'   => 'مطور ويب متكامل متخصص في React وLaravel، يقدم برامج تدريبية مكثفة في تطوير التطبيقات.',
            ],
        ];

        foreach ($map as $email => $data) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                continue;
            }

            Instructor::updateOrCreate(
                ['user_id' => $user->id],
                $data,
            );
        }

        $this->command->info('  ✓ 3 instructors seeded.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return Course[] */
    private function seedCourses(): array
    {
        $admin      = User::where('email', 'admin@emc.test')->first();
        $instrAhmad = Instructor::whereHas('user', fn ($q) => $q->where('email', 'instructor@emc.test'))->first();
        $instrSara  = Instructor::whereHas('user', fn ($q) => $q->where('email', 'instructor2@emc.test'))->first();
        $instrAbbas = Instructor::whereHas('user', fn ($q) => $q->where('email', 'instructor3@emc.test'))->first();

        $now = now();

        $courses = [
            // ── Course 1 ────────────────────────────────────────────────────
            [
                'slug'              => 'asasyat-althkaaa-alastnaaay',
                'title'             => 'أساسيات الذكاء الاصطناعي',
                'short_description' => 'برنامج تعريفي شامل بمفاهيم وتطبيقات الذكاء الاصطناعي للمبتدئين.',
                'description'       => 'يهدف هذا البرنامج إلى تعريف المشاركين بأساسيات الذكاء الاصطناعي وتطبيقاته العملية في الحياة اليومية وبيئة العمل. يشمل البرنامج محاضرات تفاعلية وورش تطبيقية.',
                'type'              => 'free',
                'is_free'           => true,
                'program_type'      => 'course',
                'price'             => 0,
                'status'            => 'published',
                'registration_open' => true,
                'registration_status' => 'open',
                'location_type'     => 'online',
                'meeting_link'      => 'https://meet.example.com/ai-basics',
                'capacity'          => 100,
                'start_date'        => $now->copy()->addDays(14)->toDateTimeString(),
                'end_date'          => $now->copy()->addDays(60)->toDateTimeString(),
                'language'          => 'ar',
                'level'             => 'beginner',
                'training_hours'    => 20,
                'target_audience'   => 'طلاب وموظفون يرغبون في فهم الذكاء الاصطناعي',
                'learning_outcomes' => json_encode([
                    'فهم مفاهيم الذكاء الاصطناعي',
                    'التعرف على أبرز التطبيقات',
                    'استخدام أدوات الذكاء الاصطناعي في العمل اليومي',
                ]),
                'instructor_id'     => $instrAhmad?->id,
                'created_by'        => $admin?->id,
            ],

            // ── Course 2 ────────────────────────────────────────────────────
            [
                'slug'              => 'prompt-engineering-advanced',
                'title'             => 'هندسة الأوامر بالذكاء الاصطناعي المتقدم',
                'short_description' => 'تعلم كيفية صياغة الأوامر الاحترافية للحصول على أفضل نتائج من نماذج الذكاء الاصطناعي.',
                'description'       => 'برنامج متقدم يتناول أساليب هندسة الأوامر للنماذج اللغوية الكبيرة. يشمل تقنيات Chain-of-Thought وFew-shot وZero-shot والتطبيقات في بيئات العمل الاحترافية.',
                'type'              => 'paid',
                'is_free'           => false,
                'program_type'      => 'course',
                'price'             => 199.00,
                'status'            => 'published',
                'registration_open' => true,
                'registration_status' => 'open',
                'location_type'     => 'online',
                'meeting_link'      => 'https://meet.example.com/prompt-eng',
                'capacity'          => 40,
                'start_date'        => $now->copy()->addDays(21)->toDateTimeString(),
                'end_date'          => $now->copy()->addDays(49)->toDateTimeString(),
                'language'          => 'ar',
                'level'             => 'intermediate',
                'training_hours'    => 15,
                'learning_outcomes' => json_encode([
                    'إتقان تقنيات هندسة الأوامر',
                    'بناء سير عمل ذكاء اصطناعي احترافية',
                    'تقييم وتحسين جودة مخرجات النماذج',
                ]),
                'instructor_id'     => $instrAbbas?->id,
                'created_by'        => $admin?->id,
            ],

            // ── Course 3 ────────────────────────────────────────────────────
            [
                'slug'              => 'cv-writing-workshop',
                'title'             => 'ورشة كتابة السيرة الذاتية',
                'short_description' => 'ورشة عملية لكتابة سيرة ذاتية احترافية تناسب سوق العمل الأوروبي.',
                'description'       => 'ورشة تدريبية مكثفة تُعنى بتطوير مهارات كتابة السيرة الذاتية وخطاب التقديم وفق معايير سوق العمل الأوروبي. تشمل تمارين عملية ومراجعات فردية.',
                'type'              => 'free',
                'is_free'           => true,
                'program_type'      => 'workshop',
                'price'             => 0,
                'status'            => 'published',
                'registration_open' => true,
                'registration_status' => 'open',
                'location_type'     => 'offline',
                'location'          => 'Breda — مركز EMC',
                'capacity'          => 25,
                'start_date'        => $now->copy()->addDays(7)->toDateTimeString(),
                'end_date'          => $now->copy()->addDays(7)->addHours(4)->toDateTimeString(),
                'language'          => 'ar',
                'level'             => 'beginner',
                'training_hours'    => 4,
                'target_audience'   => 'الباحثون عن عمل والخريجون الجدد',
                'learning_outcomes' => json_encode([
                    'كتابة سيرة ذاتية احترافية',
                    'صياغة خطاب تقديم مقنع',
                    'تحسين الملف الشخصي على LinkedIn',
                ]),
                'instructor_id'     => $instrSara?->id,
                'created_by'        => $admin?->id,
            ],

            // ── Course 4 ────────────────────────────────────────────────────
            [
                'slug'              => 'react-web-development',
                'title'             => 'تطوير الويب باستخدام React',
                'short_description' => 'برنامج شامل لتعلم تطوير واجهات المستخدم الحديثة باستخدام React وTypeScript.',
                'description'       => 'يغطي هذا البرنامج أساسيات React من المكونات والـ Hooks وإدارة الحالة وصولاً إلى بناء تطبيقات ويب متكاملة.',
                'type'              => 'paid',
                'is_free'           => false,
                'program_type'      => 'course',
                'price'             => 299.00,
                'status'            => 'draft',
                'registration_open' => false,
                'registration_status' => 'coming_soon',
                'location_type'     => 'online',
                'capacity'          => 30,
                'language'          => 'ar',
                'level'             => 'intermediate',
                'training_hours'    => 40,
                'instructor_id'     => $instrAbbas?->id,
                'created_by'        => $admin?->id,
            ],

            // ── Course 5 ────────────────────────────────────────────────────
            [
                'slug'              => 'laravel-backend-development',
                'title'             => 'Laravel Backend Development',
                'short_description' => 'Master backend development with Laravel — APIs, queues, authentication, and deployment.',
                'description'       => 'A comprehensive Laravel course covering RESTful API design, authentication with Sanctum, queue jobs, event broadcasting, and production deployment on Linux servers.',
                'type'              => 'paid',
                'is_free'           => false,
                'program_type'      => 'course',
                'price'             => 350.00,
                'status'            => 'published',
                'registration_open' => true,
                'registration_status' => 'open',
                'location_type'     => 'hybrid',
                'location'          => 'Breda — مركز EMC',
                'meeting_link'      => 'https://meet.example.com/laravel',
                'capacity'          => 35,
                'start_date'        => $now->copy()->addDays(30)->toDateTimeString(),
                'end_date'          => $now->copy()->addDays(90)->toDateTimeString(),
                'language'          => 'en',
                'level'             => 'intermediate',
                'training_hours'    => 60,
                'instructor_id'     => $instrAbbas?->id,
                'created_by'        => $admin?->id,
            ],

            // ── Course 6 ────────────────────────────────────────────────────
            [
                'slug'              => 'cybersecurity-fundamentals',
                'title'             => 'أساسيات الأمن السيبراني',
                'short_description' => 'مدخل عملي إلى مفاهيم الأمن السيبراني وحماية البيانات في العصر الرقمي.',
                'description'       => 'يقدم هذا البرنامج مقدمة شاملة لعالم الأمن السيبراني بدءاً من مفهوم التهديدات الإلكترونية وصولاً إلى أدوات الحماية الأساسية ومبادئ الأمان الرقمي.',
                'type'              => 'free',
                'is_free'           => true,
                'program_type'      => 'course',
                'price'             => 0,
                'status'            => 'published',
                'registration_open' => true,
                'registration_status' => 'open',
                'location_type'     => 'online',
                'capacity'          => 50,
                'start_date'        => $now->copy()->addDays(10)->toDateTimeString(),
                'end_date'          => $now->copy()->addDays(40)->toDateTimeString(),
                'language'          => 'ar',
                'level'             => 'beginner',
                'training_hours'    => 12,
                'instructor_id'     => $instrAhmad?->id,
                'created_by'        => $admin?->id,
            ],
        ];

        $result = [];
        foreach ($courses as $data) {
            $result[] = Course::updateOrCreate(['slug' => $data['slug']], $data);
        }

        $this->command->info('  ✓ 6 courses seeded.');

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function seedRegistrations(Course $c1, Course $c2, Course $c3, Course $c5): void
    {
        $student = User::where('email', 'student@emc.test')->first();
        $admin   = User::where('email', 'admin@emc.test')->first();

        // Authenticated registrations — unique constraint on (user_id, course_id)
        $authRegs = [
            [
                'course_id' => $c1->id,
                'user_id'   => $student?->id,
                'full_name' => $student?->name ?? 'Test Student',
                'phone'     => $student?->phone ?? '+31600000013',
                'email'     => $student?->email ?? 'student@emc.test',
                'city'      => 'Breda',
                'gender'    => 'male',
                'status'    => 'registered',
            ],
            [
                'course_id' => $c2->id,
                'user_id'   => $student?->id,
                'full_name' => $student?->name ?? 'Test Student',
                'phone'     => $student?->phone ?? '+31600000013',
                'email'     => $student?->email ?? 'student@emc.test',
                'city'      => 'Breda',
                'gender'    => 'male',
                'status'    => 'pending_payment',
            ],
            [
                'course_id' => $c3->id,
                'user_id'   => $student?->id,
                'full_name' => $student?->name ?? 'Test Student',
                'phone'     => $student?->phone ?? '+31600000013',
                'email'     => $student?->email ?? 'student@emc.test',
                'city'      => 'Breda',
                'gender'    => 'male',
                'status'    => 'attended',
            ],
            [
                'course_id' => $c5->id,
                'user_id'   => $admin?->id,
                'full_name' => $admin?->name ?? 'Admin',
                'phone'     => $admin?->phone ?? '+31600000011',
                'email'     => $admin?->email ?? 'admin@emc.test',
                'city'      => 'Rotterdam',
                'gender'    => 'male',
                'status'    => 'payment_confirmed',
            ],
        ];

        foreach ($authRegs as $reg) {
            if (! $reg['user_id']) {
                continue;
            }
            $existing = DB::table('registrations')
                ->where('user_id', $reg['user_id'])
                ->where('course_id', $reg['course_id'])
                ->whereNull('deleted_at')
                ->first();

            if (! $existing) {
                DB::table('registrations')->insert(array_merge($reg, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        // Guest registration — user_id = NULL, no unique constraint applies
        $guestExists = DB::table('registrations')
            ->whereNull('user_id')
            ->where('email', 'guest.test@example.com')
            ->where('course_id', $c1->id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $guestExists) {
            DB::table('registrations')->insert([
                'course_id'  => $c1->id,
                'user_id'    => null,
                'full_name'  => 'زائر تجريبي',
                'phone'      => '+31600000099',
                'email'      => 'guest.test@example.com',
                'city'       => 'Tilburg',
                'gender'     => 'female',
                'status'     => 'registered',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Seed payments for the registrations that need them
        $this->seedPayments($c2, $c5, $student, $admin);

        $this->command->info('  ✓ Registrations seeded.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function seedPayments(Course $c2, Course $c5, ?User $student, ?User $admin): void
    {
        // pending_payment — student's Course 2 registration
        if ($student) {
            $reg = DB::table('registrations')
                ->where('user_id', $student->id)
                ->where('course_id', $c2->id)
                ->whereNull('deleted_at')
                ->first();

            if ($reg && ! DB::table('payments')->where('registration_id', $reg->id)->exists()) {
                DB::table('payments')->insert([
                    'registration_id'   => $reg->id,
                    'amount'            => 199.00,
                    'provider'          => 'test',
                    'provider_payment_id' => 'test_pending_' . $reg->id,
                    'payment_method'    => 'ideal',
                    'status'            => 'pending_payment',
                    'confirmed_at'      => null,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
            }
        }

        // payment_confirmed — admin's Course 5 registration
        if ($admin) {
            $reg = DB::table('registrations')
                ->where('user_id', $admin->id)
                ->where('course_id', $c5->id)
                ->whereNull('deleted_at')
                ->first();

            if ($reg && ! DB::table('payments')->where('registration_id', $reg->id)->exists()) {
                DB::table('payments')->insert([
                    'registration_id'   => $reg->id,
                    'amount'            => 350.00,
                    'provider'          => 'test',
                    'provider_payment_id' => 'test_confirmed_' . $reg->id,
                    'payment_method'    => 'creditcard',
                    'status'            => 'payment_confirmed',
                    'confirmed_at'      => now()->subHour(),
                    'created_at'        => now()->subHours(2),
                    'updated_at'        => now()->subHour(),
                ]);
            }
        }

        // payment_failed — standalone record for a non-existing ghost registration
        // Create a fake guest registration first so the FK is valid
        $failedRegExists = DB::table('registrations')
            ->where('email', 'failed.payment@example.com')
            ->where('course_id', $c2->id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $failedRegExists) {
            $failedRegId = DB::table('registrations')->insertGetId([
                'course_id'  => $c2->id,
                'user_id'    => null,
                'full_name'  => 'دفع فاشل تجريبي',
                'phone'      => '+31600000098',
                'email'      => 'failed.payment@example.com',
                'status'     => 'registered',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('payments')->insert([
                'registration_id'   => $failedRegId,
                'amount'            => 199.00,
                'provider'          => 'test',
                'provider_payment_id' => 'test_failed_' . $failedRegId,
                'payment_method'    => 'ideal',
                'status'            => 'payment_failed',
                'confirmed_at'      => null,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }

        $this->command->info('  ✓ Payments seeded (pending, confirmed, failed).');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function seedLmsContent(Course $c1, Course $c2): void
    {
        $moduleTemplates = [
            ['title' => 'مقدمة في البرنامج',   'sort_order' => 1],
            ['title' => 'المفاهيم الأساسية',    'sort_order' => 2],
            ['title' => 'التطبيق العملي',        'sort_order' => 3],
        ];

        foreach ([$c1, $c2] as $course) {
            $modules = [];

            foreach ($moduleTemplates as $mod) {
                $modules[] = CourseModule::updateOrCreate(
                    ['course_id' => $course->id, 'title' => $mod['title']],
                    [
                        'description' => 'وصف ' . $mod['title'],
                        'sort_order'  => $mod['sort_order'],
                        'status'      => 'active',
                    ],
                );
            }

            $sessionTemplates = [
                ['title' => 'اللقاء التعريفي',          'module_idx' => 0, 'sort_order' => 1],
                ['title' => 'الجلسة الأولى',             'module_idx' => 1, 'sort_order' => 2],
                ['title' => 'ورشة تطبيقية مباشرة',      'module_idx' => 2, 'sort_order' => 3],
            ];

            $sessions = [];
            foreach ($sessionTemplates as $ses) {
                $module = $modules[$ses['module_idx']] ?? null;
                $sessions[] = CourseSession::updateOrCreate(
                    ['course_id' => $course->id, 'title' => $ses['title']],
                    [
                        'module_id'     => $module?->id,
                        'description'   => 'وصف ' . $ses['title'],
                        'start_at'      => now()->addDays(14 + $ses['sort_order'] * 7),
                        'end_at'        => now()->addDays(14 + $ses['sort_order'] * 7)->addHours(2),
                        'location_type' => 'online',
                        'meeting_link'  => 'https://meet.example.com/' . $course->slug . '-s' . $ses['sort_order'],
                        'sort_order'    => $ses['sort_order'],
                        'status'        => 'published',
                        'is_visible'    => true,
                    ],
                );
            }

            $materialTemplates = [
                [
                    'title'        => 'ملف تعريفي للبرنامج',
                    'type'         => 'pdf',
                    'file_url'     => 'https://example.com/files/' . $course->slug . '-intro.pdf',
                    'sort_order'   => 1,
                    'module_idx'   => 0,
                    'session_idx'  => 0,
                ],
                [
                    'title'        => 'رابط مصادر خارجية',
                    'type'         => 'link',
                    'external_url' => 'https://resources.example.com/' . $course->slug,
                    'sort_order'   => 2,
                    'module_idx'   => 1,
                    'session_idx'  => 1,
                ],
                [
                    'title'        => 'تسجيل الجلسة الأولى',
                    'type'         => 'video',
                    'external_url' => 'https://recordings.example.com/' . $course->slug . '-session1',
                    'sort_order'   => 3,
                    'module_idx'   => 1,
                    'session_idx'  => 1,
                ],
            ];

            foreach ($materialTemplates as $mat) {
                $module  = $modules[$mat['module_idx']] ?? null;
                $session = $sessions[$mat['session_idx']] ?? null;

                CourseMaterial::updateOrCreate(
                    ['course_id' => $course->id, 'title' => $mat['title']],
                    [
                        'module_id'         => $module?->id,
                        'course_session_id' => $session?->id,
                        'description'       => $mat['title'],
                        'file_url'          => $mat['file_url'] ?? null,
                        'external_url'      => $mat['external_url'] ?? null,
                        'type'              => $mat['type'],
                        'sort_order'        => $mat['sort_order'],
                        'is_visible'        => true,
                    ],
                );
            }

            $assignmentTemplates = [
                [
                    'title'           => 'واجب تطبيقي قصير',
                    'description'     => 'واجب تطبيقي يختبر فهم المبادئ الأساسية.',
                    'instructions'    => 'أجب عن الأسئلة التالية بأسلوبك الخاص.',
                    'deadline'        => now()->addDays(28),
                    'max_points'      => 50,
                    'submission_type' => 'text',
                    'is_required'     => false,
                    'status'          => 'published',
                    'sort_order'      => 1,
                    'module_idx'      => 1,
                ],
                [
                    'title'           => 'مشروع نهائي بسيط',
                    'description'     => 'مشروع ختامي يطبّق فيه المتدرب ما تعلّمه.',
                    'instructions'    => 'ابنِ مشروعاً صغيراً وارفعه في ملف مضغوط.',
                    'deadline'        => now()->addDays(56),
                    'max_points'      => 100,
                    'submission_type' => 'file',
                    'is_required'     => true,
                    'status'          => 'published',
                    'sort_order'      => 2,
                    'module_idx'      => 2,
                ],
            ];

            foreach ($assignmentTemplates as $asg) {
                $module = $modules[$asg['module_idx']] ?? null;

                CourseAssignment::updateOrCreate(
                    ['course_id' => $course->id, 'title' => $asg['title']],
                    [
                        'module_id'       => $module?->id,
                        'description'     => $asg['description'],
                        'instructions'    => $asg['instructions'],
                        'deadline'        => $asg['deadline'],
                        'max_points'      => $asg['max_points'],
                        'submission_type' => $asg['submission_type'],
                        'is_required'     => $asg['is_required'],
                        'is_visible'      => true,
                        'status'          => $asg['status'],
                        'sort_order'      => $asg['sort_order'],
                    ],
                );
            }
        }

        $this->command->info('  ✓ LMS content seeded (modules, sessions, materials, assignments) for courses 1 & 2.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function seedNotifications(Course $c1, Course $c2): void
    {
        $student    = User::where('email', 'student@emc.test')->first();
        $instructor = User::where('email', 'instructor@emc.test')->first();
        $admin      = User::where('email', 'admin@emc.test')->first();

        $notifications = [
            [
                'user_id'     => $student?->id,
                'type'        => 'registration_success',
                'title'       => 'تم تسجيلك بنجاح',
                'message'     => 'تم تسجيل انضمامك إلى برنامج "' . $c1->title . '" بنجاح.',
                'entity_type' => 'course',
                'entity_id'   => $c1->id,
                'action_url'  => '/courses/' . $c1->slug,
                'icon'        => 'check-circle',
                'color'       => 'green',
                'is_read'     => false,
            ],
            [
                'user_id'     => $student?->id,
                'type'        => 'payment_confirmed',
                'title'       => 'تأكيد الدفع',
                'message'     => 'تم تأكيد دفعتك لبرنامج "' . $c2->title . '" بنجاح.',
                'entity_type' => 'course',
                'entity_id'   => $c2->id,
                'action_url'  => '/courses/' . $c2->slug,
                'icon'        => 'credit-card',
                'color'       => 'blue',
                'is_read'     => true,
                'read_at'     => now()->subHour(),
            ],
            [
                'user_id'     => $instructor?->id,
                'type'        => 'instructor_assigned',
                'title'       => 'تم تعيينك كمدرب',
                'message'     => 'تم تعيينك مدرباً لبرنامج "' . $c1->title . '".',
                'entity_type' => 'course',
                'entity_id'   => $c1->id,
                'action_url'  => '/instructor/courses/' . $c1->id,
                'icon'        => 'academic-cap',
                'color'       => 'purple',
                'is_read'     => false,
            ],
            [
                'user_id'     => $admin?->id,
                'type'        => 'new_registration',
                'title'       => 'تسجيل جديد',
                'message'     => 'تم استلام تسجيل جديد في برنامج "' . $c1->title . '".',
                'entity_type' => 'course',
                'entity_id'   => $c1->id,
                'action_url'  => '/admin/courses/' . $c1->id . '/registrations',
                'icon'        => 'users',
                'color'       => 'orange',
                'is_read'     => false,
            ],
        ];

        foreach ($notifications as $notif) {
            if (! $notif['user_id']) {
                continue;
            }

            $exists = DB::table('platform_notifications')
                ->where('user_id', $notif['user_id'])
                ->where('type', $notif['type'])
                ->where('entity_id', $notif['entity_id'])
                ->exists();

            if (! $exists) {
                DB::table('platform_notifications')->insert(array_merge(
                    $notif,
                    ['created_at' => now(), 'updated_at' => now()],
                ));
            }
        }

        $this->command->info('  ✓ Platform notifications seeded.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function seedStudentProgress(Course $c1, Course $c3): void
    {
        $student = User::where('email', 'student@emc.test')->first();
        if (! $student) {
            return;
        }

        $rows = [
            [
                'user_id'             => $student->id,
                'course_id'           => $c1->id,
                'progress_percentage' => 35,
                'completed_sessions'  => 1,
                'total_sessions'      => 3,
                'attendance_percentage' => 33,
                'assignment_completion_percentage' => 0,
                'status'              => 'in_progress',
            ],
            [
                'user_id'             => $student->id,
                'course_id'           => $c3->id,
                'progress_percentage' => 0,
                'completed_sessions'  => 0,
                'total_sessions'      => 1,
                'attendance_percentage' => 0,
                'assignment_completion_percentage' => 0,
                'status'              => 'in_progress',
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('student_progress')
                ->where('user_id', $row['user_id'])
                ->where('course_id', $row['course_id'])
                ->exists();

            if (! $exists) {
                DB::table('student_progress')->insert(array_merge($row, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            } else {
                DB::table('student_progress')
                    ->where('user_id', $row['user_id'])
                    ->where('course_id', $row['course_id'])
                    ->update(array_merge($row, ['updated_at' => now()]));
            }
        }

        $this->command->info('  ✓ Student progress seeded.');
    }
}
