<?php

namespace Database\Factories;

use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Track>
 */
class TrackFactory extends Factory
{
    protected $model = Track::class;

    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'title' => $title,
            'slug' => Str::slug($title) . '-' . fake()->unique()->numberBetween(1, 999999),
            'description' => fake()->paragraph(),
            'outcome_title' => fake()->sentence(),
            'duration' => '4 weeks',
            'level' => 'beginner',
            'status' => 'active',
            'image' => null,
            'price' => null,
            'is_free' => true,
        ];
    }
}
