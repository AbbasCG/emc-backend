<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'track_id' => null,
            'instructor_id' => null,
            'title' => $title,
            'slug' => Str::slug($title) . '-' . fake()->unique()->numberBetween(1, 999999),
            'description' => fake()->paragraph(),
            'short_description' => fake()->sentence(),
            'type' => 'free',
            'price' => 0,
            'location' => null,
            'is_online' => false,
            'start_date' => null,
            'end_date' => null,
            'capacity' => 20,
            'status' => 'published',
            'duration' => null,
            'training_hours' => null,
            'target_audience' => null,
            'language' => null,
            'level' => null,
            'study_days' => null,
            'study_time' => null,
            'certificate' => null,
            'learning_outcomes' => null,
            'course_image' => null,
        ];
    }

    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'free',
            'price' => 0,
        ]);
    }

    public function paid(float $price = 99.99): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'paid',
            'price' => $price,
        ]);
    }
}
