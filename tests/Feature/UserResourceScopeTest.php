<?php

namespace Tests\Feature;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\VolunteerPointAward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class UserResourceScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_learning_and_points_summaries_are_only_for_the_account_holder(): void
    {
        $owner = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);

        $asOwner = Request::create('/');
        $asOwner->setUserResolver(fn () => $owner);

        $own = (new UserResource($owner))->toArray($asOwner);
        $this->assertArrayHasKey('has_enrolled_courses', $own);
        $this->assertArrayHasKey('impact_points_balance', $own);

        $seenByOther = (new UserResource($other))->resolve($asOwner);
        $this->assertArrayNotHasKey('has_enrolled_courses', $seenByOther);
        $this->assertArrayNotHasKey('impact_points_balance', $seenByOther);

        // login / register have no authenticated user yet but still describe the caller's own account
        $login = UserResource::forSelf($owner)->resolve(Request::create('/'));
        $this->assertArrayHasKey('has_enrolled_courses', $login);
        $this->assertArrayHasKey('impact_points_balance', $login);
    }

    public function test_listing_users_does_not_run_per_user_summary_queries(): void
    {
        $viewer = User::factory()->create(['role' => 'instructor']);
        $students = User::factory()->count(6)->create(['role' => 'student']);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $viewer);

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        foreach ($students as $student) {
            (new UserResource($student))->resolve($request);
        }
        $sql = collect(\DB::getQueryLog())->pluck('query')->implode("\n");

        $this->assertStringNotContainsString('volunteer_point_awards', $sql);
        $this->assertStringNotContainsString('class_group_students', $sql);
    }
}
