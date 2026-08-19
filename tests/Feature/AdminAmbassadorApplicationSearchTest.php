<?php

namespace Tests\Feature;

use App\Models\AmbassadorApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAmbassadorApplicationSearchTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function makeApplication(array $overrides = []): AmbassadorApplication
    {
        return AmbassadorApplication::create(array_merge([
            'uuid'              => (string) Str::uuid(),
            'status'            => 'new',
            'is_draft'          => false,
            'full_name'         => 'Applicant Name',
            'email'             => 'applicant'.Str::random(6).'@example.com',
            'mobile_phone'      => '+31612345678',
            'nationality'       => 'يمني',
            'gender'            => 'male',
            'country'           => 'اليمن',
            'city'              => 'صنعاء',
            'university_name'   => 'جامعة صنعاء',
            'major'             => 'علوم الحاسوب',
            'motivation_why'    => 'أريد المساهمة في التحول الرقمي ونشر المعرفة التقنية.',
            'contribution_what' => 'تنظيم فعاليات وورش عمل للطلاب.',
            'expected_gain'     => 'تطوير مهارات القيادة والتواصل.',
        ], $overrides));
    }

    public function test_search_by_applicant_name(): void
    {
        $admin = $this->makeAdmin();
        $this->makeApplication(['full_name' => 'أحمد محمد']);
        $this->makeApplication(['full_name' => 'سارة علي']);

        $response = $this->actingAs($admin)->getJson('/api/admin/ambassador-applications?search=أحمد');

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('أحمد محمد', $response->json('data.0.full_name'));
    }

    public function test_search_by_email(): void
    {
        $admin = $this->makeAdmin();
        $this->makeApplication(['email' => 'taiz.student@example.com', 'full_name' => 'Email Match']);
        $this->makeApplication(['email' => 'other@example.com', 'full_name' => 'Other']);

        $response = $this->actingAs($admin)->getJson('/api/admin/ambassador-applications?search=taiz.student');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Email Match', $response->json('data.0.full_name'));
    }

    public function test_search_by_university(): void
    {
        $admin = $this->makeAdmin();
        $this->makeApplication(['university_name' => 'جامعة صنعاء', 'full_name' => 'Sanaa Applicant']);
        $this->makeApplication(['university_name' => 'Avans', 'full_name' => 'Avans Applicant']);

        $response = $this->actingAs($admin)->getJson('/api/admin/ambassador-applications?search='.urlencode('جامعة صنعاء'));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Sanaa Applicant', $response->json('data.0.full_name'));
    }

    public function test_search_by_country_arabic(): void
    {
        $admin = $this->makeAdmin();
        $this->makeApplication(['country' => 'هولندا', 'city' => 'بريدا', 'full_name' => 'NL Applicant']);
        $this->makeApplication(['country' => 'اليمن', 'city' => 'تعز', 'full_name' => 'YE Applicant']);

        $response = $this->actingAs($admin)->getJson('/api/admin/ambassador-applications?search='.urlencode('هولندا'));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('NL Applicant', $response->json('data.0.full_name'));
    }

    public function test_search_by_city_arabic(): void
    {
        $admin = $this->makeAdmin();
        $this->makeApplication(['city' => 'تعز', 'full_name' => 'Taiz Applicant']);
        $this->makeApplication(['city' => 'صنعاء', 'full_name' => 'Sanaa City Applicant']);

        $response = $this->actingAs($admin)->getJson('/api/admin/ambassador-applications?search='.urlencode('تعز'));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Taiz Applicant', $response->json('data.0.full_name'));
    }

    public function test_search_by_specialization(): void
    {
        $admin = $this->makeAdmin();
        $this->makeApplication(['major' => 'Software', 'full_name' => 'Soft Applicant']);
        $this->makeApplication(['major' => 'Medicine', 'full_name' => 'Med Applicant']);

        $response = $this->actingAs($admin)->getJson('/api/admin/ambassador-applications?search=Software');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Soft Applicant', $response->json('data.0.full_name'));
    }

    public function test_english_search_works(): void
    {
        $admin = $this->makeAdmin();
        $this->makeApplication(['university_name' => 'Avans University', 'full_name' => 'English Uni']);
        $this->makeApplication(['university_name' => 'جامعة صنعاء', 'full_name' => 'Arabic Uni']);

        $response = $this->actingAs($admin)->getJson('/api/admin/ambassador-applications?search=Avans');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('English Uni', $response->json('data.0.full_name'));
    }

    public function test_country_and_city_filters_work_together(): void
    {
        $admin = $this->makeAdmin();
        $this->makeApplication(['country' => 'هولندا', 'city' => 'بريدا', 'full_name' => 'Breda']);
        $this->makeApplication(['country' => 'هولندا', 'city' => 'أمستردام', 'full_name' => 'Amsterdam']);
        $this->makeApplication(['country' => 'اليمن', 'city' => 'بريدا', 'full_name' => 'Wrong Country']);

        $response = $this->actingAs($admin)->getJson(
            '/api/admin/ambassador-applications?country='.urlencode('هولندا').'&city='.urlencode('بريدا')
        );

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Breda', $response->json('data.0.full_name'));
    }

    public function test_university_and_status_filters_work_together(): void
    {
        $admin = $this->makeAdmin();
        $this->makeApplication(['university_name' => 'Avans', 'status' => 'new', 'full_name' => 'New Avans']);
        $this->makeApplication(['university_name' => 'Avans', 'status' => 'approved', 'full_name' => 'Approved Avans']);
        $this->makeApplication(['university_name' => 'جامعة صنعاء', 'status' => 'new', 'full_name' => 'New Sanaa']);

        $response = $this->actingAs($admin)->getJson('/api/admin/ambassador-applications?university=Avans&status=new');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('New Avans', $response->json('data.0.full_name'));
    }

    public function test_date_range_filter_works(): void
    {
        $admin = $this->makeAdmin();
        $old = $this->makeApplication(['full_name' => 'Old App']);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $recent = $this->makeApplication(['full_name' => 'Recent App']);
        $recent->forceFill(['created_at' => now()->subDay()])->save();

        $from = now()->subDays(2)->toDateString();
        $to = now()->toDateString();

        $response = $this->actingAs($admin)->getJson(
            "/api/admin/ambassador-applications?date_from={$from}&date_to={$to}"
        );

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('full_name')->all();
        $this->assertContains('Recent App', $names);
        $this->assertNotContains('Old App', $names);
    }

    public function test_unauthorized_user_receives_403(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $this->makeApplication();

        $this->actingAs($student)
            ->getJson('/api/admin/ambassador-applications')
            ->assertForbidden();

        $this->actingAs($student)
            ->getJson('/api/admin/ambassador-applications/filter-options')
            ->assertForbidden();
    }

    public function test_pagination_metadata_remains_correct(): void
    {
        $admin = $this->makeAdmin();
        for ($i = 0; $i < 25; $i++) {
            $this->makeApplication(['full_name' => "Applicant {$i}"]);
        }

        $response = $this->actingAs($admin)->getJson('/api/admin/ambassador-applications?per_page=10&page=2');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 3);

        $this->assertCount(10, $response->json('data'));
    }

    public function test_filter_options_return_distinct_values(): void
    {
        $admin = $this->makeAdmin();
        $this->makeApplication([
            'country' => 'هولندا',
            'city' => 'بريدا',
            'university_name' => 'Avans',
            'major' => 'Software',
        ]);
        $this->makeApplication([
            'country' => 'هولندا',
            'city' => 'بريدا',
            'university_name' => 'Avans',
            'major' => 'Software',
        ]);
        $this->makeApplication([
            'country' => 'اليمن',
            'city' => 'تعز',
            'university_name' => 'جامعة صنعاء',
            'major' => 'علوم الحاسوب',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/ambassador-applications/filter-options');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertEqualsCanonicalizing(['هولندا', 'اليمن'], $data['countries']);
        $this->assertEqualsCanonicalizing(['بريدا', 'تعز'], $data['cities']);
        $this->assertEqualsCanonicalizing(['Avans', 'جامعة صنعاء'], $data['universities']);
        $this->assertEqualsCanonicalizing(['Software', 'علوم الحاسوب'], $data['specializations']);
    }

    public function test_filter_options_respect_country_dependency(): void
    {
        $admin = $this->makeAdmin();
        $this->makeApplication(['country' => 'هولندا', 'city' => 'بريدا', 'university_name' => 'Avans']);
        $this->makeApplication(['country' => 'اليمن', 'city' => 'تعز', 'university_name' => 'جامعة صنعاء']);

        $response = $this->actingAs($admin)->getJson(
            '/api/admin/ambassador-applications/filter-options?country='.urlencode('هولندا')
        );

        $response->assertOk();
        $this->assertSame(['بريدا'], $response->json('data.cities'));
        $this->assertSame(['Avans'], $response->json('data.universities'));
    }

    public function test_empty_search_does_not_break_endpoint(): void
    {
        $admin = $this->makeAdmin();
        $this->makeApplication();

        $response = $this->actingAs($admin)->getJson('/api/admin/ambassador-applications?search=');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_hr_manager_can_access_list_and_filter_options(): void
    {
        $hr = $this->makeAdmin('hr_manager');
        $this->makeApplication();

        $this->actingAs($hr)->getJson('/api/admin/ambassador-applications')->assertOk();
        $this->actingAs($hr)->getJson('/api/admin/ambassador-applications/filter-options')->assertOk();
    }

    public function test_specialization_alias_filter_works(): void
    {
        $admin = $this->makeAdmin();
        $this->makeApplication(['major' => 'علوم الحاسوب', 'full_name' => 'CS']);
        $this->makeApplication(['major' => 'Medicine', 'full_name' => 'Med']);

        $response = $this->actingAs($admin)->getJson(
            '/api/admin/ambassador-applications?specialization='.urlencode('علوم الحاسوب')
        );

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('CS', $response->json('data.0.full_name'));
    }
}
