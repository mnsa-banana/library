<?php

namespace Tests\Feature\Api\V1\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_password_when_current_password_is_correct(): void
    {
        $user = User::factory()->create(['password' => Hash::make('current-pw')]);

        $resp = $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/password', [
            'current_password' => 'current-pw',
            'password' => 'new-strong-pw',
            'password_confirmation' => 'new-strong-pw',
        ]);

        $resp->assertNoContent();
        $this->assertTrue(Hash::check('new-strong-pw', $user->fresh()->password));
    }

    public function test_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('current-pw')]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/password', [
            'current_password' => 'WRONG',
            'password' => 'new-strong-pw',
            'password_confirmation' => 'new-strong-pw',
        ])->assertStatus(422);
    }

    public function test_revokes_other_tokens_but_keeps_current(): void
    {
        $user = User::factory()->create(['password' => Hash::make('current-pw')]);
        $current = $user->createToken('current')->plainTextToken;
        $user->createToken('other-device');
        $this->assertSame(2, $user->tokens()->count());

        $this->withHeader('Authorization', 'Bearer ' . $current)
            ->postJson('/api/v1/account/password', [
                'current_password' => 'current-pw',
                'password' => 'new-strong-pw',
                'password_confirmation' => 'new-strong-pw',
            ])->assertNoContent();

        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    public function test_requires_auth(): void
    {
        $this->postJson('/api/v1/account/password', [])->assertStatus(401);
    }
}
