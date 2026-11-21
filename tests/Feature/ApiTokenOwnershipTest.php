<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The API-token screen lists, and revokes, only the caller's own tokens.
 *
 * Regression: index() used `tokens()->whereNull('last_used_at')->orWhereNotNull('last_used_at')`. The unparenthesised
 * orWhere escaped the tokenable scope, so any admin saw the metadata of EVERY user's used tokens, impersonation
 * tokens included.
 */
class ApiTokenOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX_URIS = ['/api/admin/api-tokens', '/api/developer/api-tokens'];

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    public function test_index_lists_only_the_callers_tokens(): void
    {
        $caller = $this->user('admin');
        $other  = $this->user('admin');
        $victim = $this->user('student');

        $ownUnused = $caller->createToken('own-unused', ['read:courses'])->accessToken;
        $ownUsed   = $caller->createToken('own-used', ['read:reports'])->accessToken;
        $ownUsed->forceFill(['last_used_at' => now()])->save();

        // Another user's USED tokens and a used impersonation token are what the old query leaked; the unused one is a control.
        $foreignUsed = $other->createToken('foreign-used', ['write:webhooks'])->accessToken;
        $foreignUsed->forceFill(['last_used_at' => now()])->save();
        $foreignUnused = $other->createToken('foreign-unused')->accessToken;
        $impersonation = $victim->createToken('impersonation-by-'.$other->id, ['via-impersonation'], now()->addMinutes(30))->accessToken;
        $impersonation->forceFill(['last_used_at' => now()])->save();

        foreach (self::INDEX_URIS as $uri) {
            $ids = $this->actingAs($caller)->getJson($uri)->assertOk()->json('data.*.id');

            sort($ids);
            $this->assertSame([$ownUnused->id, $ownUsed->id], $ids, "{$uri} must list exactly the caller's tokens.");
            $this->assertNotContains($foreignUsed->id, $ids);
            $this->assertNotContains($foreignUnused->id, $ids);
            $this->assertNotContains($impersonation->id, $ids);
        }
    }

    public function test_an_admin_cannot_revoke_another_users_token(): void
    {
        $caller = $this->user('admin');
        $other  = $this->user('admin');
        $foreign = $other->createToken('foreign', ['read:courses'])->accessToken;

        $this->actingAs($caller)->deleteJson('/api/admin/api-tokens/'.$foreign->id)->assertNotFound();
        $this->actingAs($caller)->deleteJson('/api/developer/api-tokens/'.$foreign->id)->assertNotFound();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreign->id]);
    }

    public function test_an_admin_can_revoke_their_own_token(): void
    {
        $caller = $this->user('admin');
        $own = $caller->createToken('own', ['read:courses'])->accessToken;

        $this->actingAs($caller)->deleteJson('/api/admin/api-tokens/'.$own->id)->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $own->id]);
    }
}
