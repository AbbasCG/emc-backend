<?php

namespace Database\Factories;

use App\Models\Certificate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    public function definition(): array
    {
        return [
            'user_id'               => User::factory(),
            'certificate_type'      => 'course_completion',
            'title'                 => 'شهادة إتمام: ' . fake()->words(3, true),
            'recipient_name'        => fake()->name(),
            'recipient_email'       => fake()->safeEmail(),
            'status'                => 'issued',
            'pdf_generation_status' => 'completed',
            'related_type'          => 'course',
            'related_id'            => null,
        ];
    }
}
