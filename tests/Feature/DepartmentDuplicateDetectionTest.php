<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\DepartmentDuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DepartmentDuplicateDetectionTest extends TestCase
{
    use RefreshDatabase;

    private const AI = 'إدارة الذكاء الاصطناعي والتحول الرقمي';

    private function dept(string $slug, ?string $nameAr, array $over = []): Department
    {
        return Department::create(array_merge([
            'name' => $nameAr ?? $slug, 'name_ar' => $nameAr, 'name_en' => null,
            'slug' => $slug, 'status' => 'active', 'is_active' => true,
        ], $over));
    }

    private function detect(bool $archived = false)
    {
        return app(DepartmentDuplicateDetector::class)->detect($archived);
    }

    public function test_finds_an_exact_arabic_duplicate(): void
    {
        $a = $this->dept('ai-transformation', self::AI);
        $b = $this->dept('ai-digital-transformation', self::AI);
        $this->dept('finance', 'الإدارة المالية');

        $groups = $this->detect();

        $this->assertCount(1, $groups);
        $this->assertSame(DepartmentDuplicateDetector::EXACT, $groups[0]['tier']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $groups[0]['departments']->pluck('id')->all());
    }

    public function test_exact_english_name_also_groups(): void
    {
        $this->dept('a', 'اسم أول', ['name_en' => 'Digital Ambassadors']);
        $this->dept('b', 'اسم ثان', ['name_en' => 'digital ambassadors']);

        $groups = $this->detect();

        $this->assertCount(1, $groups);
        $this->assertSame(DepartmentDuplicateDetector::EXACT, $groups[0]['tier']);
    }

    /** @return array<string, array{string}> */
    public static function harmlessSpellings(): array
    {
        return [
            'doubled space' => ['إدارة  الذكاء الاصطناعي والتحول الرقمي'],
            'detached waw' => ['إدارة الذكاء الاصطناعي و التحول الرقمي'],
            'zero width' => ["إدارة\u{200B} الذكاء الاصطناعي والتحول الرقمي"],
            'tatweel' => ['إدارة الذكـاء الاصطناعي والتحـول الرقمي'],
            'alef variant' => ['ادارة الذكاء الاصطناعي والتحول الرقمي'],
        ];
    }

    #[DataProvider('harmlessSpellings')]
    public function test_harmless_spacing_and_letter_variants_are_high_confidence_duplicates(string $variant): void
    {
        $a = $this->dept('one', self::AI);
        $b = $this->dept('two', $variant);

        $groups = $this->detect();

        $this->assertCount(1, $groups);
        $this->assertSame(DepartmentDuplicateDetector::HIGH, $groups[0]['tier']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $groups[0]['departments']->pluck('id')->all());
    }

    public function test_a_trailing_space_alone_is_still_an_exact_duplicate(): void
    {
        $this->dept('one', self::AI);
        $this->dept('two', self::AI.' ');

        $this->assertSame(DepartmentDuplicateDetector::EXACT, $this->detect()[0]['tier']);
    }

    public function test_taa_marbuta_difference_is_review_only_never_a_safe_group(): void
    {
        $this->dept('one', 'إدارة الصحة النفسية');
        $this->dept('two', 'إدارة الصحة النفسيه');

        $groups = $this->detect();

        $this->assertCount(1, $groups);
        $this->assertSame(DepartmentDuplicateDetector::REVIEW, $groups[0]['tier']);
    }

    public function test_unrelated_departments_are_not_flagged(): void
    {
        $this->dept('a', 'الإدارة المالية');
        $this->dept('b', 'إدارة الإعلام والتسويق');
        $this->dept('c', 'إدارة الشراكات والعلاقات');

        $this->assertCount(0, $this->detect());
    }

    public function test_three_way_duplicates_form_one_group(): void
    {
        $this->dept('a', self::AI);
        $this->dept('b', self::AI.' ');
        $this->dept('c', 'إدارة  الذكاء الاصطناعي والتحول الرقمي');

        $groups = $this->detect();

        $this->assertCount(1, $groups);
        $this->assertCount(3, $groups[0]['departments']);
    }

    public function test_archived_departments_are_excluded_unless_requested(): void
    {
        $this->dept('a', self::AI);
        $this->dept('b', self::AI, ['status' => 'archived']);

        $this->assertCount(0, $this->detect());
        $this->assertCount(1, $this->detect(true));
    }

    public function test_group_for_name_matches_through_harmless_differences(): void
    {
        $this->dept('a', self::AI);
        $this->dept('b', self::AI);

        $group = app(DepartmentDuplicateDetector::class)->groupForName('إدارة الذكاء  الاصطناعي و التحول الرقمي ');

        $this->assertNotNull($group);
        $this->assertCount(2, $group['departments']);
        $this->assertNull(app(DepartmentDuplicateDetector::class)->groupForName('اسم غير موجود'));
    }

    public function test_find_conflict_ignores_archived_and_the_department_itself(): void
    {
        $live = $this->dept('a', self::AI);
        $this->dept('old', self::AI.' ', ['status' => 'archived']);
        $detector = app(DepartmentDuplicateDetector::class);

        $this->assertSame($live->id, $detector->findConflict(self::AI.' ')->id);
        $this->assertNull($detector->findConflict(self::AI, null, $live->id), 'a department never conflicts with itself');
        $this->assertNull($detector->findConflict('اسم جديد تماماً'));
        $this->assertNull($detector->findConflict(null, null));
    }

    // ── departments:duplicates (read-only audit) ────────────────────────────

    public function test_audit_command_reports_the_group_and_recommends_the_led_department(): void
    {
        $leader = User::factory()->create();
        $this->dept('ai-digital-transformation', self::AI);
        $keep = $this->dept('ai-transformation', self::AI, ['leader_id' => $leader->id]);

        $this->artisan('departments:duplicates')
            ->expectsOutputToContain('EXACT')
            ->expectsOutputToContain("Recommended canonical: #{$keep->id}")
            ->expectsOutputToContain('No data was changed')
            ->assertSuccessful();
    }

    public function test_audit_command_writes_nothing(): void
    {
        $a = $this->dept('a', self::AI);
        $b = $this->dept('b', self::AI);
        TeamProfile::create(['department_id' => $b->id, 'name_ar' => 'x', 'position_ar' => 'y', 'is_active' => true]);
        $before = [Department::count(), TeamProfile::first()->department_id, $b->fresh()->updated_at->toIso8601String()];

        $this->artisan('departments:duplicates', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, [Department::count(), TeamProfile::first()->department_id, $b->fresh()->updated_at->toIso8601String()]);
    }

    public function test_audit_command_is_quiet_and_successful_without_duplicates(): void
    {
        $this->dept('a', 'الإدارة المالية');

        $this->artisan('departments:duplicates', ['--fail-on-duplicates' => true])
            ->expectsOutputToContain('No duplicate departments found')
            ->assertSuccessful();
    }

    public function test_fail_on_duplicates_exits_non_zero_only_for_safe_groups(): void
    {
        $this->dept('a', self::AI);
        $this->dept('b', self::AI);
        $this->artisan('departments:duplicates', ['--fail-on-duplicates' => true])->assertFailed();
    }

    public function test_review_only_pairs_do_not_fail_the_ci_flag(): void
    {
        $this->dept('a', 'إدارة الصحة النفسية');
        $this->dept('b', 'إدارة الصحة النفسيه');

        $this->artisan('departments:duplicates', ['--fail-on-duplicates' => true])
            ->expectsOutputToContain('Review only')
            ->assertSuccessful();
    }
}
