<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'registration_id' => Registration::factory(),
            'amount' => 50,
            'provider' => 'fake',
            'provider_payment_id' => 'tmp',
            'checkout_url' => 'http://localhost/fake',
            'status' => 'Pending Payment',
        ];
    }
}
