<?php

namespace Tests\Feature\Api\V1\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_token_resets_password_and_returns_user_with_fresh_token(): void
    {
        $user = User::factory()->create(['email' => 'parent@example.com', 'password' => Hash::make('old-pw')]);
        $token = Password::createToken($user);

        $resp = $this->postJson('/api/v1/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-strong-pw',
            'password_confirmation' => 'new-strong-pw',
        ]);

        $resp->assertOk()->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);
        $this->assertTrue(Hash::check('new-strong-pw', $user->fresh()->password));
    }

    public function test_revokes_all_existing_sanctum_tokens_on_success(): void
    {
        $user = User::factory()->create(['email' => 'parent@example.com', 'password' => Hash::make('old')]);
        $user->createToken('old-session');
        $user->createToken('another-device');
        $this->assertSame(2, $user->tokens()->count());

        $resetToken = Password::createToken($user);

        $this->postJson('/api/v1/password/reset', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'new-strong-pw',
            'password_confirmation' => 'new-strong-pw',
        ])->assertOk();

        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    public function test_invalid_token_returns_422(): void
    {
        $user = User::factory()->create(['email' => 'parent@example.com']);

        $this->postJson('/api/v1/password/reset', [
            'email' => $user->email,
            'token' => 'totally-bogus',
            'password' => 'new-strong-pw',
            'password_confirmation' => 'new-strong-pw',
        ])->assertStatus(422);
    }

    public function test_reused_token_fails(): void
    {
        $user = User::factory()->create(['email' => 'parent@example.com']);
        $token = Password::createToken($user);

        $first = $this->postJson('/api/v1/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'aaaaaaaa1',
            'password_confirmation' => 'aaaaaaaa1',
        ]);
        $first->assertOk();

        $second = $this->postJson('/api/v1/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'bbbbbbbb2',
            'password_confirmation' => 'bbbbbbbb2',
        ]);
        $second->assertStatus(422);
    }
}
