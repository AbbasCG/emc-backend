<?php

namespace Database\Factories;

use App\Models\Workshop;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Workshop> */
class WorkshopFactory extends Factory
{
    protected $model = Workshop::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'course_id'        => Course::factory(),
            'instructor_id'    => null,
            'assigned_by'      => null,
            'title'            => $title,
            'slug'             => Str::slug($title) . '-' . fake()->unique()->numberBetween(1, 999999),
            'description'      => fake()->paragraph(),
            'status'           => 'published',
            'start_date'       => now()->addWeek()->toDateString(),
            'start_time'       => '10:00:00',
            'end_date'         => now()->addWeek()->toDateString(),
            'end_time'         => '12:00:00',
            'location_type'    => 'online',
            'meeting_link'     => null,
            'registration_open'=> true,
            'sort_order'       => 0,
        ];
    }
}
