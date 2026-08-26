<?php

namespace Tests\Feature;

use App\Models\WorkshopRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * /submit-workshop Step 4 pricing: paid workshops require a positive numeric
 * price, free workshops don't. Covers the exact bug reported — the price
 * value must actually reach and persist in the database.
 */
class WorkshopRequestPriceTest extends TestCase
{
    use RefreshDatabase;

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'requester_name' => 'أحمد محمد',
            'requester_email' => 'ahmad@example.com',
            'program_name' => 'ورشة تجريبية',
            'speaker_name' => 'د. سارة',
            'speaker_job_title' => 'محاضرة',
            'speaker_photo' => UploadedFile::fake()->image('speaker.jpg'),
            'topics' => 'محاور الورشة',
            'categories' => ['تقنية'],
            'target_audience' => 'الجميع',
            'proposed_date_1' => now()->addDays(10)->toDateString(),
            'proposed_time_1' => '10:00 - 12:00',
            'proposed_date_2' => now()->addDays(11)->toDateString(),
            'proposed_time_2' => '10:00 - 12:00',
            'proposed_date_3' => now()->addDays(12)->toDateString(),
            'proposed_time_3' => '10:00 - 12:00',
            'location_types' => ['online'],
            'price_type' => 'free',
        ], $overrides);
    }

    public function test_paid_workshop_with_valid_price_is_stored(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/workshop-requests', $this->basePayload([
            'price_type' => 'paid',
            'price_amount' => '49.99',
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('workshop_requests', [
            'price_type' => 'paid',
            'price_amount' => 49.99,
        ]);
    }

    public function test_paid_workshop_without_price_is_rejected(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/workshop-requests', $this->basePayload([
            'price_type' => 'paid',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('price_amount');
    }

    public function test_paid_workshop_with_zero_price_is_rejected(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/workshop-requests', $this->basePayload([
            'price_type' => 'paid',
            'price_amount' => '0',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('price_amount');
    }

    public function test_paid_workshop_with_negative_price_is_rejected(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/workshop-requests', $this->basePayload([
            'price_type' => 'paid',
            'price_amount' => '-10',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('price_amount');
    }

    public function test_paid_workshop_with_non_numeric_price_is_rejected(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/workshop-requests', $this->basePayload([
            'price_type' => 'paid',
            'price_amount' => 'abc',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('price_amount');
    }

    public function test_free_workshop_does_not_require_price(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/workshop-requests', $this->basePayload([
            'price_type' => 'free',
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('workshop_requests', [
            'price_type' => 'free',
        ]);
        $id = WorkshopRequest::latest('id')->first()->id;
        $this->assertNull(WorkshopRequest::find($id)->price_amount);
    }

    public function test_price_amount_is_ignored_when_workshop_is_free_even_if_sent(): void
    {
        Storage::fake('public');

        // A stray price_amount alongside price_type=free must not block submission.
        $response = $this->postJson('/api/workshop-requests', $this->basePayload([
            'price_type' => 'free',
            'price_amount' => '25',
        ]));

        $response->assertCreated();
    }

    public function test_decimal_price_persists_with_two_decimal_precision(): void
    {
        Storage::fake('public');

        $this->postJson('/api/workshop-requests', $this->basePayload([
            'price_type' => 'paid',
            'price_amount' => '100.5',
        ]))->assertCreated();

        $record = WorkshopRequest::latest('id')->first();
        $this->assertSame('100.50', $record->price_amount);
    }
}
