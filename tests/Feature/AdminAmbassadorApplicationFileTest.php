<?php

namespace Tests\Feature;

use App\Models\AmbassadorApplication;
use App\Models\AmbassadorApplicationFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAmbassadorApplicationFileTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(string $role = 'super_admin'): User
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

    private function makeFile(AmbassadorApplication $app, array $overrides = []): AmbassadorApplicationFile
    {
        return AmbassadorApplicationFile::create(array_merge([
            'ambassador_application_id' => $app->id,
            'category'                  => 'cv',
            'original_name'             => 'cv.pdf',
            'stored_name'               => Str::uuid().'.pdf',
            'mime_type'                 => 'application/pdf',
            'extension'                 => 'pdf',
            'original_size'             => 1024,
            'checksum'                  => Str::random(64),
            'disk'                      => 'private',
            'path'                      => 'ambassador-files/'.Str::uuid().'.pdf',
            'processing_status'         => 'optimized',
            'uploaded_at'               => now(),
        ], $overrides));
    }

    public function test_authorized_admin_can_list_application_files(): void
    {
        Storage::fake('private');
        $admin = $this->makeAdmin();
        $app = $this->makeApplication();
        $this->makeFile($app);

        $response = $this->actingAs($admin)->getJson("/api/admin/ambassador-applications/{$app->id}/files");

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_application_with_cv_returns_200_and_no_download_url(): void
    {
        Storage::fake('private');
        $admin = $this->makeAdmin();
        $app = $this->makeApplication();
        $file = $this->makeFile($app);
        Storage::disk('private')->put($file->path, '%PDF-1.4 fake content');

        $response = $this->actingAs($admin)->getJson("/api/admin/ambassador-applications/{$app->id}/files");

        $response->assertOk();
        $payload = $response->json('data.0');
        $this->assertArrayHasKey('preview_url', $payload);
        $this->assertArrayNotHasKey('download_url', $payload, 'download_url must never be exposed — preview-only privacy policy');
        $this->assertArrayNotHasKey('path', $payload);
        $this->assertArrayNotHasKey('disk', $payload);
        $this->assertArrayNotHasKey('stored_name', $payload);
    }

    public function test_pdf_preview_returns_inline_response(): void
    {
        Storage::fake('private');
        $admin = $this->makeAdmin();
        $app = $this->makeApplication();
        $file = $this->makeFile($app);
        Storage::disk('private')->put($file->path, '%PDF-1.4 fake content');

        $response = $this->actingAs($admin)
            ->getJson("/api/admin/ambassador-applications/{$app->id}/files/{$file->id}/preview");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('inline', $disposition);
        $this->assertStringNotContainsString('attachment', $disposition);
    }

    public function test_missing_physical_file_does_not_produce_500(): void
    {
        Storage::fake('private');
        $admin = $this->makeAdmin();
        $app = $this->makeApplication();
        // DB row exists but nothing written to disk.
        $file = $this->makeFile($app);

        $response = $this->actingAs($admin)
            ->getJson("/api/admin/ambassador-applications/{$app->id}/files/{$file->id}/preview");

        $response->assertNotFound();
        $response->assertJsonPath('success', false);
    }

    public function test_file_belonging_to_another_application_is_blocked(): void
    {
        Storage::fake('private');
        $admin = $this->makeAdmin();
        $appA = $this->makeApplication();
        $appB = $this->makeApplication();
        $fileOfB = $this->makeFile($appB);
        Storage::disk('private')->put($fileOfB->path, '%PDF-1.4 fake content');

        $response = $this->actingAs($admin)
            ->getJson("/api/admin/ambassador-applications/{$appA->id}/files/{$fileOfB->id}/preview");

        $response->assertNotFound();
    }

    public function test_unauthorized_user_cannot_view_files(): void
    {
        Storage::fake('private');
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $app = $this->makeApplication();
        $this->makeFile($app);

        $response = $this->actingAs($student)->getJson("/api/admin/ambassador-applications/{$app->id}/files");

        $response->assertForbidden();
    }

    public function test_guest_cannot_view_files(): void
    {
        $app = $this->makeApplication();
        $this->makeFile($app);

        $response = $this->getJson("/api/admin/ambassador-applications/{$app->id}/files");

        $response->assertUnauthorized();
    }

    public function test_raw_private_path_is_not_exposed_in_list(): void
    {
        Storage::fake('private');
        $admin = $this->makeAdmin();
        $app = $this->makeApplication();
        $file = $this->makeFile($app, ['path' => 'ambassador-files/secret-path-abc.pdf']);
        Storage::disk('private')->put($file->path, '%PDF-1.4 fake content');

        $response = $this->actingAs($admin)->getJson("/api/admin/ambassador-applications/{$app->id}/files");

        $body = $response->getContent();
        $this->assertStringNotContainsString('secret-path-abc', $body);
        $this->assertStringNotContainsString('ambassador-files/', $body);
    }

    public function test_application_with_zero_files_returns_empty_list_not_500(): void
    {
        $admin = $this->makeAdmin();
        $app = $this->makeApplication();

        $response = $this->actingAs($admin)->getJson("/api/admin/ambassador-applications/{$app->id}/files");

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertCount(0, $response->json('data'));
    }
}
