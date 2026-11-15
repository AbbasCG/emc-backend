<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AssignmentUploads\PhpUploadLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentUploadRulesAndCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_rules_endpoint_returns_the_single_source_of_truth(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $mb = 1048576;

        $response = $this->actingAs($student, 'sanctum')->getJson('/api/student/assignment-upload-rules')->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.max_files', 5)
            ->assertJsonPath('data.max_total_bytes', 25 * $mb)
            ->assertJsonStructure(['data' => ['max_files', 'max_total_bytes', 'groups' => [['key', 'label_ar', 'extensions', 'max_bytes']], 'accept']]);

        $groups = collect($response->json('data.groups'))->keyBy('key');
        $this->assertSame(['jpg', 'jpeg', 'png', 'webp'], $groups['images']['extensions']);
        $this->assertSame(5 * $mb, $groups['images']['max_bytes']);
        $this->assertSame(['pdf', 'doc', 'docx'], $groups['documents']['extensions']);
        $this->assertSame(10 * $mb, $groups['documents']['max_bytes']);
        $this->assertSame(['txt'], $groups['text']['extensions']);
        $this->assertSame(2 * $mb, $groups['text']['max_bytes']);
        $this->assertSame(['xls', 'xlsx', 'csv'], $groups['sheets']['extensions']);
        $this->assertSame(['ppt', 'pptx'], $groups['presentations']['extensions']);
        $this->assertSame(['zip', 'rar', '7z'], $groups['archives']['extensions']);
        $this->assertSame(
            '.jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.txt,.xls,.xlsx,.csv,.ppt,.pptx,.zip,.rar,.7z',
            $response->json('data.accept'),
        );

        // Never advertises anything executable.
        foreach (['exe', 'php', 'svg', 'html', 'js', 'bat'] as $bad) {
            $this->assertStringNotContainsString('.'.$bad.',', $response->json('data.accept').',');
        }
    }

    public function test_rules_follow_config_overrides(): void
    {
        config(['assignment_uploads.max_files' => 3, 'assignment_uploads.max_total_mb' => 12, 'assignment_uploads.groups.images.max_mb' => 1]);
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $response = $this->actingAs($student, 'sanctum')->getJson('/api/student/assignment-upload-rules')->assertOk();

        $this->assertSame(3, $response->json('data.max_files'));
        $this->assertSame(12 * 1048576, $response->json('data.max_total_bytes'));
        $this->assertSame(1048576, collect($response->json('data.groups'))->firstWhere('key', 'images')['max_bytes']);
    }

    public function test_rules_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/student/assignment-upload-rules')->assertStatus(401);
    }

    private function fakeLimits(int $upload, int $post, int $uploads): void
    {
        $this->app->instance(PhpUploadLimits::class, new class($upload, $post, $uploads) extends PhpUploadLimits {
            public function __construct(private int $u, private int $p, private int $n)
            {
            }

            public function uploadMaxFilesizeBytes(): int
            {
                return $this->u;
            }

            public function postMaxBytes(): int
            {
                return $this->p;
            }

            public function maxFileUploads(): int
            {
                return $this->n;
            }

            public function raw(string $key): string
            {
                return match ($key) {
                    'upload_max_filesize' => ($this->u / 1048576).'M',
                    'post_max_size' => ($this->p / 1048576).'M',
                    default => '',
                };
            }
        });
    }

    public function test_checker_passes_with_adequate_php_limits(): void
    {
        $this->fakeLimits(10 * 1048576, 32 * 1048576, 20);

        $this->artisan('assignments:check-upload-limits')
            ->expectsOutputToContain('[OK] upload_max_filesize')
            ->expectsOutputToContain('[OK] post_max_size')
            ->expectsOutputToContain('[OK] max_file_uploads')
            ->expectsOutputToContain('RESULT: OK')
            ->assertExitCode(0);
    }

    public function test_checker_fails_and_prints_the_required_values(): void
    {
        $this->fakeLimits(2 * 1048576, 8 * 1048576, 3);

        $this->artisan('assignments:check-upload-limits')
            ->expectsOutputToContain('[FAIL] upload_max_filesize = 2M  (required/المطلوب: 10M)')
            ->expectsOutputToContain('[FAIL] post_max_size = 8M  (required/المطلوب: 32M)')
            ->expectsOutputToContain('[FAIL] max_file_uploads')
            ->expectsOutputToContain('client_max_body_size 32m;')
            ->expectsOutputToContain('RESULT: FAIL')
            ->assertExitCode(1);
    }

    public function test_checker_treats_post_max_size_zero_as_unlimited_and_boundaries_as_ok(): void
    {
        $this->fakeLimits(10 * 1048576, 0, 5);
        $this->artisan('assignments:check-upload-limits')->assertExitCode(0);

        // Exactly total (25MB) + overhead (3MB) = 28MB is enough; 27MB is not.
        $this->fakeLimits(10 * 1048576, 28 * 1048576, 5);
        $this->artisan('assignments:check-upload-limits')->assertExitCode(0);
        $this->fakeLimits(10 * 1048576, 27 * 1048576, 5);
        $this->artisan('assignments:check-upload-limits')->assertExitCode(1);
    }

    public function test_checker_follows_config(): void
    {
        config(['assignment_uploads.groups.documents.max_mb' => 40, 'assignment_uploads.max_total_mb' => 80]);
        $this->fakeLimits(10 * 1048576, 32 * 1048576, 20);

        $this->artisan('assignments:check-upload-limits')
            ->expectsOutputToContain('required/المطلوب: 40M')
            ->expectsOutputToContain('required/المطلوب: 88M')
            ->assertExitCode(1);
    }

    // ── Round 2 ────────────────────────────────────────────────────────────────────────────────────────────

    public function test_default_configuration_is_consistent(): void
    {
        $this->assertSame([], app(\App\Services\AssignmentUploads\AssignmentUploadPolicy::class)->configErrors());
    }

    public function test_a_total_limit_below_the_largest_file_limit_is_reported_and_fails_the_checker(): void
    {
        config(['assignment_uploads.max_total_mb' => 5]);
        $this->fakeLimits(10 * 1048576, 32 * 1048576, 20);

        $errors = app(\App\Services\AssignmentUploads\AssignmentUploadPolicy::class)->configErrors();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('smaller than the largest per-file limit', $errors[0]);

        $this->artisan('assignments:check-upload-limits')
            ->expectsOutputToContain('[FAIL] config/assignment_uploads.php')
            ->expectsOutputToContain('RESULT: FAIL')
            ->assertExitCode(1);
    }

    public function test_an_extension_without_a_content_verifier_is_a_config_error(): void
    {
        config(['assignment_uploads.groups.images.extensions' => ['jpg', 'gif']]);

        $errors = app(\App\Services\AssignmentUploads\AssignmentUploadPolicy::class)->configErrors();
        $this->assertStringContainsString('gif', implode(' ', $errors));
    }

    public function test_checker_fails_when_a_required_php_extension_is_missing(): void
    {
        $this->app->instance(PhpUploadLimits::class, new class extends PhpUploadLimits {
            public function uploadMaxFilesizeBytes(): int
            {
                return 10 * 1048576;
            }

            public function postMaxBytes(): int
            {
                return 32 * 1048576;
            }

            public function maxFileUploads(): int
            {
                return 20;
            }

            public function extensionLoaded(string $extension): bool
            {
                return $extension !== 'zip';
            }
        });

        $this->artisan('assignments:check-upload-limits')
            ->expectsOutputToContain('[FAIL] PHP extension zip')
            ->expectsOutputToContain('[OK] PHP extension dom')
            ->assertExitCode(1);
    }

    public function test_the_required_extensions_are_the_documented_ones(): void
    {
        $this->assertSame(['zip', 'fileinfo', 'mbstring', 'dom', 'libxml'], PhpUploadLimits::REQUIRED_EXTENSIONS);
    }

    public function test_blank_environment_values_mean_the_default_never_zero(): void
    {
        $keys = ['ASSIGNMENT_UPLOAD_MAX_FILES', 'ASSIGNMENT_UPLOAD_MAX_TOTAL_MB', 'ASSIGNMENT_UPLOAD_IMAGE_MAX_MB', 'ASSIGNMENT_UPLOAD_ZIP_MAX_ENTRIES'];
        foreach ($keys as $key) {
            putenv($key.'=');
        }

        try {
            $config = require config_path('assignment_uploads.php');
        } finally {
            foreach ($keys as $key) {
                putenv($key);
            }
        }

        $this->assertSame(5, $config['max_files']);
        $this->assertSame(25.0, $config['max_total_mb']);
        $this->assertSame(5.0, $config['groups']['images']['max_mb']);
        $this->assertSame(10000, $config['zip']['max_entries']);
    }

    public function test_explicit_environment_values_still_override(): void
    {
        putenv('ASSIGNMENT_UPLOAD_MAX_FILES=7');
        try {
            $config = require config_path('assignment_uploads.php');
        } finally {
            putenv('ASSIGNMENT_UPLOAD_MAX_FILES');
        }

        $this->assertSame(7, $config['max_files']);
    }
}
