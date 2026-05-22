<?php

namespace Tests\Feature\Api\V1\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_user_and_tokens_when_password_is_correct(): void
    {
        Http::fake();
        $user = User::factory()->create(['password' => Hash::make('pw')]);
        $user->createToken('one');
        $user->createToken('two');

        $resp = $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/account', [
            'current_password' => 'pw',
        ]);

        $resp->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(0, \DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
    }

    public function test_does_not_call_revenuecat(): void
    {
        Http::fake();
        $user = User::factory()->create(['password' => Hash::make('pw')]);
        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/account', [
            'current_password' => 'pw',
        ])->assertNoContent();

        Http::assertNothingSent();
    }

    public function test_rejects_wrong_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('pw')]);
        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/account', [
            'current_password' => 'WRONG',
        ])->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_requires_auth(): void
    {
        $this->deleteJson('/api/v1/account', [])->assertStatus(401);
    }
}
