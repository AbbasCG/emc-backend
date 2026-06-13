<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Instructor;
use App\Models\LearningPath;
use App\Models\User;
use App\Models\VolunteerRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PublicInstructorTest extends TestCase
{
    use RefreshDatabase;

    private function createPublicInstructor(array $userOverrides = [], array $profileOverrides = []): Instructor
    {
        $user = User::factory()->create(array_merge([
            'role'      => 'instructor',
            'is_active' => true,
        ], $userOverrides));

        return Instructor::create(array_merge([
            'user_id' => $user->id,
            'name'    => $user->name,
            'title'   => 'Senior Trainer',
            'bio'     => 'Experienced EMC instructor.',
        ], $profileOverrides));
    }

    public function test_public_instructors_index_returns_active_instructor_profiles(): void
    {
        $profile = $this->createPublicInstructor(['name' => 'Sara Al-Mutairi']);

        Course::factory()->create([
            'instructor_id' => $profile->id,
            'status'        => 'published',
            'title'         => 'Published Course',
        ]);

        $this->createPublicInstructor([
            'name'      => 'Inactive Instructor',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/instructors')
            ->assertOk()
            ->assertJson(['success' => true]);

        $rows = collect($response->json('data'));
        $this->assertTrue($rows->contains(fn ($row) => ($row['name'] ?? '') === 'Sara Al-Mutairi'));
        $this->assertFalse($rows->contains(fn ($row) => ($row['name'] ?? '') === 'Inactive Instructor'));

        $sara = $rows->firstWhere('name', 'Sara Al-Mutairi');
        $this->assertSame(Instructor::publicSlug('Sara Al-Mutairi', $profile->id), $sara['slug']);
        $this->assertSame(1, $sara['courses_count']);
        $this->assertArrayHasKey('avatar_url', $sara);
        $this->assertArrayNotHasKey('email', $sara);
    }

    public function test_public_instructor_show_by_numeric_id(): void
    {
        $profile = $this->createPublicInstructor(['name' => 'Omar Hassan']);

        $this->getJson("/api/instructors/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $profile->id)
            ->assertJsonPath('data.name', 'Omar Hassan')
            ->assertJsonPath('data.slug', Instructor::publicSlug('Omar Hassan', $profile->id));
    }

    public function test_public_instructor_show_by_slug(): void
    {
        $profile = $this->createPublicInstructor(['name' => 'Layla Noor']);
        $slug    = Instructor::publicSlug('Layla Noor', $profile->id);

        $this->getJson("/api/instructors/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.slug', $slug);

        $this->getJson("/api/instructors/slug/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.id', $profile->id);
    }

    public function test_invalid_instructor_identifier_returns_clean_404(): void
    {
        $this->getJson('/api/instructors/undefined')
            ->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Instructor not found.',
            ]);
    }

    public function test_legacy_teacher_role_is_included_in_public_directory(): void
    {
        $user = User::factory()->create([
            'role'      => 'teacher',
            'is_active' => true,
            'name'      => 'Legacy Teacher',
        ]);

        Instructor::create([
            'user_id' => $user->id,
            'name'    => $user->name,
            'title'   => 'Teacher',
        ]);

        $this->getJson('/api/instructors')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Legacy Teacher']);
    }

    public function test_public_instructor_detail_includes_related_courses_and_paths(): void
    {
        $profile = $this->createPublicInstructor(['name' => 'Detail Instructor']);

        Course::factory()->create([
            'instructor_id' => $profile->id,
            'status'        => 'published',
            'title'         => 'Visible Course',
            'slug'          => 'visible-course',
        ]);

        Course::factory()->create([
            'instructor_id' => $profile->id,
            'status'        => 'draft',
            'title'         => 'Hidden Course',
            'slug'          => 'hidden-course',
        ]);

        LearningPath::create([
            'instructor_id'     => $profile->id,
            'title'             => 'Visible Path',
            'slug'              => 'visible-path',
            'status'            => 'published',
            'duration'          => '8 weeks',
            'duration_unit'     => 'weeks',
            'language'          => 'ar',
            'level'             => 'intermediate',
            'enrollment_open'   => true,
        ]);

        $response = $this->getJson("/api/instructors/{$profile->id}")
            ->assertOk();

        $courses = collect($response->json('data.courses'));
        $paths   = collect($response->json('data.learning_paths'));

        $this->assertTrue($courses->contains(fn ($c) => ($c['slug'] ?? '') === 'visible-course'));
        $this->assertFalse($courses->contains(fn ($c) => ($c['slug'] ?? '') === 'hidden-course'));
        $this->assertTrue($paths->contains(fn ($p) => ($p['slug'] ?? '') === 'visible-path'));
    }
}
