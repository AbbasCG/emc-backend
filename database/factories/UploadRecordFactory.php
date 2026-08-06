<?php

namespace Database\Factories;

use App\Models\UploadRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UploadRecord>
 */
class UploadRecordFactory extends Factory
{
    protected $model = UploadRecord::class;

    public function definition(): array
    {
        $original  = fake()->numberBetween(100_000, 5_000_000);
        $optimized = (int) ($original * fake()->randomFloat(2, 0.2, 0.9));

        return [
            'user_id'           => User::factory(),
            'context'           => fake()->randomElement(['course', 'workshop', 'instructor', 'student', 'assignment', 'certificate', 'review']),
            'file_hash'         => hash('sha256', Str::random(32)),
            'original_filename' => fake()->word() . '.jpg',
            'original_size'     => $original,
            'optimized_size'    => $optimized,
            'mime_type'         => 'image/jpeg',
            'disk'              => 'public',
            'path'              => 'uploads/courses/2026/06/' . Str::random(16) . '.jpg',
            'url'               => 'http://localhost/storage/uploads/courses/2026/06/' . Str::random(16) . '.jpg',
            'status'            => 'complete',
            'job_id'            => null,
            'error_message'     => null,
            'metadata'          => ['saved_percent' => max(0, (int) round((1 - $optimized / $original) * 100))],
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending', 'optimized_size' => null, 'path' => null, 'url' => null]);
    }

    public function failed(): static
    {
        return $this->state(['status' => 'failed', 'optimized_size' => null, 'error_message' => 'Processing error.']);
    }
}
