<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Registration>
 */
class RegistrationFactory extends Factory
{
    protected $model = Registration::class;

    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'full_name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->safeEmail(),
            'city' => fake()->city(),
            'gender' => null,
            'notes' => null,
            'status' => 'Registered',
        ];
    }
}
