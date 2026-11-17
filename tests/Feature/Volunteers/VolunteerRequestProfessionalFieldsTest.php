<?php

namespace Tests\Feature\Volunteers;

use App\Models\VolunteerRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The four optional professional questions on the public apply form: languages, LinkedIn, a short bio and the consent to show
 * professional data. An unanswered question stays NULL (consent included: NULL is "not answered", never false).
 */
class VolunteerRequestProfessionalFieldsTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MakesCvFiles;

    protected function setUp(): void
    {
        parent::setUp();

        // The public endpoint allows 5 posts a minute per IP; these tests post many payloads on purpose.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'full_name' => 'Sara Ahmad', 'email' => 'sara@example.com', 'phone' => '+31600000000', 'country' => 'NL', 'city' => 'Amsterdam',
            'desired_department' => 'التسويق والإعلام', 'agree_terms' => true,
        ], $over);
    }

    public function test_the_new_fields_are_accepted_stored_and_exposed(): void
    {
        $res = $this->postJson('/api/volunteer-requests', $this->payload([
            'languages' => ['العربية', 'English'],
            'linkedin_url' => 'https://www.linkedin.com/in/sara-ahmad',
            'professional_bio' => 'مصممة جرافيك مهتمة بالعمل المجتمعي.',
            'professional_profile_consent' => true,
        ]))->assertCreated()
            ->assertJsonPath('data.languages', ['العربية', 'English'])
            ->assertJsonPath('data.linkedin_url', 'https://www.linkedin.com/in/sara-ahmad')
            ->assertJsonPath('data.professional_bio', 'مصممة جرافيك مهتمة بالعمل المجتمعي.')
            ->assertJsonPath('data.professional_profile_consent', true);

        $row = VolunteerRequest::findOrFail($res->json('data.id'));
        $this->assertSame(['العربية', 'English'], $row->languages);
        $this->assertSame('https://www.linkedin.com/in/sara-ahmad', $row->linkedin_url);
        $this->assertTrue($row->professional_profile_consent);
    }

    public function test_omitted_fields_stay_null_and_consent_is_never_defaulted(): void
    {
        $res = $this->postJson('/api/volunteer-requests', $this->payload())->assertCreated()
            ->assertJsonPath('data.languages', null)
            ->assertJsonPath('data.linkedin_url', null)
            ->assertJsonPath('data.professional_bio', null)
            ->assertJsonPath('data.professional_profile_consent', null);

        $row = VolunteerRequest::findOrFail($res->json('data.id'));
        $this->assertNull($row->languages);
        $this->assertNull($row->linkedin_url);
        $this->assertNull($row->professional_bio);
        $this->assertNull($row->professional_profile_consent, 'consent not answered must stay NULL, not false');
        $this->assertNull($row->getRawOriginal('professional_profile_consent'));
    }

    public function test_an_explicit_refusal_is_stored_as_false_not_null(): void
    {
        $this->postJson('/api/volunteer-requests', $this->payload(['professional_profile_consent' => false]))
            ->assertCreated()->assertJsonPath('data.professional_profile_consent', false);

        $this->assertFalse(VolunteerRequest::firstOrFail()->professional_profile_consent);
    }

    public function test_consent_sent_as_multipart_text_is_stored_as_a_real_boolean(): void
    {
        Storage::fake('private');

        // The frontend posts multipart/form-data (the form carries a CV): every value arrives as text.
        foreach (['0' => false, '1' => true] as $sent => $expected) {
            VolunteerRequest::query()->delete();

            $this->post('/api/volunteer-requests', $this->payload([
                'professional_profile_consent' => (string) $sent,
                'languages' => json_encode(['العربية'], JSON_UNESCAPED_UNICODE),
                'cv_file' => $this->pdfCv(),
            ]), ['Accept' => 'application/json'])->assertCreated();

            $row = VolunteerRequest::firstOrFail();
            $this->assertSame($expected, $row->professional_profile_consent, "consent '{$sent}'");
            $this->assertSame(['العربية'], $row->languages);
            $this->assertNotNull($row->cv_file_path);
        }
    }

    public function test_empty_strings_from_a_form_post_are_treated_as_not_answered(): void
    {
        $this->postJson('/api/volunteer-requests', $this->payload([
            'languages' => '', 'linkedin_url' => '', 'professional_bio' => '', 'professional_profile_consent' => '',
        ]))->assertCreated();

        $row = VolunteerRequest::firstOrFail();
        $this->assertNull($row->languages);
        $this->assertNull($row->linkedin_url);
        $this->assertNull($row->professional_bio);
        $this->assertNull($row->professional_profile_consent);
    }

    public function test_languages_may_arrive_as_a_json_string(): void
    {
        $this->postJson('/api/volunteer-requests', $this->payload(['languages' => json_encode(['العربية', 'Français'], JSON_UNESCAPED_UNICODE)]))
            ->assertCreated()->assertJsonPath('data.languages', ['العربية', 'Français']);

        $this->assertSame(['العربية', 'Français'], VolunteerRequest::firstOrFail()->languages);
    }

    public function test_duplicate_languages_are_dropped_case_insensitively_keeping_the_first_spelling(): void
    {
        $this->postJson('/api/volunteer-requests', $this->payload(['languages' => ['English', 'العربية', 'english', ' ENGLISH ', 'Français', 'العربية']]))
            ->assertCreated()->assertJsonPath('data.languages', ['English', 'العربية', 'Français']);

        $this->assertSame(['English', 'العربية', 'Français'], VolunteerRequest::firstOrFail()->languages);
    }

    public function test_bad_languages_are_rejected_with_a_422(): void
    {
        $tooMany = array_map(fn ($i) => "لغة{$i}", range(1, 21));

        foreach ([
            'not json', '{"a":"b"}', '5', 5, true,                // not a list
            [['x']], [['a' => 'b']], [5], [null], [''],           // odd items
            [str_repeat('x', 51)], $tooMany,                      // limits
            ['<b>English</b>'], ['<script>alert(1)</script>'], ['Eng<i>lish'],  // real tags
        ] as $bad) {
            $this->postJson('/api/volunteer-requests', $this->payload(['languages' => $bad]))
                ->assertStatus(422)->assertJsonValidationErrors(['languages']);
        }
        $this->assertSame(0, VolunteerRequest::count());

        // exactly 20 items of 50 characters are fine, and "<" that is not a tag is plain text
        $this->postJson('/api/volunteer-requests', $this->payload(['languages' => array_map(fn ($i) => str_pad((string) $i, 50, 'x'), range(1, 20))]))->assertCreated();
        $this->postJson('/api/volunteer-requests', $this->payload(['languages' => ['<3 Arabic']]))->assertCreated();
    }

    public function test_a_bio_over_500_characters_is_rejected_and_500_is_accepted(): void
    {
        $this->postJson('/api/volunteer-requests', $this->payload(['professional_bio' => str_repeat('ب', 501)]))
            ->assertStatus(422)->assertJsonValidationErrors(['professional_bio']);

        $this->postJson('/api/volunteer-requests', $this->payload(['professional_bio' => str_repeat('ب', 500)]))->assertCreated();
    }

    public function test_real_html_tags_in_the_bio_are_rejected(): void
    {
        foreach (['نص <script>alert(1)</script>', '<b>bold</b>', 'x </p> y', 'x < b>y', '<!-- c -->', '<?php echo 1; ?>', '<img src=x onerror=alert(1)>'] as $bad) {
            $this->postJson('/api/volunteer-requests', $this->payload(['professional_bio' => $bad]))
                ->assertStatus(422)->assertJsonValidationErrors(['professional_bio']);
        }
        $this->assertSame(0, VolunteerRequest::count());
    }

    public function test_a_bare_less_than_sign_in_the_bio_is_plain_text_and_accepted(): void
    {
        foreach (['I <3 volunteering', 'خبرة أقل من <5 سنوات', '1 < 2 and 3 > 2', 'a <> b'] as $ok) {
            VolunteerRequest::query()->delete();
            $this->postJson('/api/volunteer-requests', $this->payload(['professional_bio' => $ok]))
                ->assertCreated()->assertJsonPath('data.professional_bio', $ok);
        }
    }

    public function test_a_linkedin_value_that_is_not_a_linkedin_http_url_is_rejected(): void
    {
        foreach ([
            'linkedin.com/in/sara', 'not a url', 'javascript:alert(1)', 'ftp://linkedin.com/in/x',
            'https://evil.com/in/sara', 'https://notlinkedin.com/in/sara', 'https://linkedin.com.evil.com/in/sara',
            'https://evil.com/linkedin.com', 'https://evil.com?u=linkedin.com',
            'https://user:pass@www.linkedin.com/in/sara', 'https://linkedin.com@evil.com/in/sara', 'https://www.linkedin.com@evil.com/',
            "https://www.linkedin.com/in/sa\nra", 'https://evil.com\\@www.linkedin.com/in/x',
        ] as $bad) {
            $this->postJson('/api/volunteer-requests', $this->payload(['linkedin_url' => $bad]))
                ->assertStatus(422)->assertJsonValidationErrors(['linkedin_url']);
        }
        $this->assertSame(0, VolunteerRequest::count());
    }

    public function test_linkedin_hosts_are_limited_to_linkedin_com_and_its_subdomains(): void
    {
        foreach (['https://linkedin.com/in/sara', 'http://www.linkedin.com/in/sara', 'https://sa.linkedin.com/in/sara', 'https://LinkedIn.com/in/sara'] as $ok) {
            VolunteerRequest::query()->delete();
            $this->postJson('/api/volunteer-requests', $this->payload(['linkedin_url' => $ok]))->assertCreated();
        }
    }

    public function test_arrays_and_objects_sent_for_text_fields_are_a_422_never_a_500(): void
    {
        foreach ([
            'professional_bio'             => [['x'], ['a' => 'b'], [['n' => 1]]],
            'linkedin_url'                 => [['https://www.linkedin.com/in/x'], ['a' => 'b']],
            'professional_profile_consent' => [[true], ['a' => 'b'], 'maybe'],
            'languages'                    => [['a' => 'b'], [['x']], 5.5],
        ] as $field => $shapes) {
            foreach ($shapes as $shape) {
                $this->postJson('/api/volunteer-requests', $this->payload([$field => $shape]))
                    ->assertStatus(422)->assertJsonValidationErrors([$field]);
            }
        }
        $this->assertSame(0, VolunteerRequest::count());
    }
}
