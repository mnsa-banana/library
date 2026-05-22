<?php

namespace Tests\Feature\Api\V1\Account;

use App\Mail\EmailChangeMail;
use App\Models\EmailChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ChangeEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_sends_mail_to_new_address_and_persists_pending_row(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => Hash::make('pw')]);

        $resp = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Brand', 'mnsa-safe')
            ->postJson('/api/v1/account/email', [
                'current_password' => 'pw',
                'new_email' => 'new@example.com',
            ]);

        $resp->assertNoContent();
        Mail::assertSent(EmailChangeMail::class, fn ($m) => $m->hasTo('new@example.com') && $m->brand->key === 'mnsa-safe');
        $this->assertDatabaseHas('email_changes', ['user_id' => $user->id, 'new_email' => 'new@example.com']);
    }

    public function test_request_rejects_wrong_current_password(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => Hash::make('pw')]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/email', [
            'current_password' => 'WRONG',
            'new_email' => 'new@example.com',
        ])->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_request_rejects_email_already_taken(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => Hash::make('pw')]);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/email', [
            'current_password' => 'pw',
            'new_email' => 'taken@example.com',
        ])->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_new_request_invalidates_older_pending_rows(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => Hash::make('pw')]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/email', [
            'current_password' => 'pw',
            'new_email' => 'first@example.com',
        ])->assertNoContent();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/email', [
            'current_password' => 'pw',
            'new_email' => 'second@example.com',
        ])->assertNoContent();

        $unused = EmailChange::where('user_id', $user->id)->whereNull('used_at')->whereNotNull('expires_at')->count();
        $this->assertSame(1, $unused);
    }

    public function test_confirm_swaps_email_and_notifies_old_address(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'old@example.com', 'password' => Hash::make('pw')]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/email', [
            'current_password' => 'pw',
            'new_email' => 'new@example.com',
        ])->assertNoContent();

        $token = null;
        $changeId = null;
        Mail::assertSent(\App\Mail\EmailChangeMail::class, function ($m) use (&$token, &$changeId) {
            $token = $m->token;
            $changeId = $m->changeId;
            return true;
        });

        $resp = $this->postJson('/api/v1/account/email/confirm', [
            'id' => $changeId,
            'token' => $token,
        ]);

        $resp->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('new@example.com', $user->fresh()->email);
        Mail::assertSent(\App\Mail\EmailChangedNotice::class, fn ($m) => $m->hasTo('old@example.com'));
    }

    public function test_confirm_rejects_invalid_token(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'old@example.com', 'password' => Hash::make('pw')]);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/email', [
            'current_password' => 'pw',
            'new_email' => 'new@example.com',
        ])->assertNoContent();

        $changeId = \App\Models\EmailChange::query()->latest('id')->first()->id;

        $this->postJson('/api/v1/account/email/confirm', [
            'id' => $changeId,
            'token' => 'BOGUS',
        ])->assertStatus(422);
        $this->assertSame('old@example.com', $user->fresh()->email);
    }

    public function test_confirm_rejects_expired_token(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'old@example.com', 'password' => Hash::make('pw')]);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/email', [
            'current_password' => 'pw',
            'new_email' => 'new@example.com',
        ])->assertNoContent();

        $token = null;
        $changeId = null;
        Mail::assertSent(\App\Mail\EmailChangeMail::class, function ($m) use (&$token, &$changeId) {
            $token = $m->token;
            $changeId = $m->changeId;
            return true;
        });

        \App\Models\EmailChange::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/account/email/confirm', [
            'id' => $changeId,
            'token' => $token,
        ])->assertStatus(410);
    }

    public function test_confirm_handles_users_email_unique_violation_gracefully(): void
    {
        Mail::fake();
        $alice = User::factory()->create(['password' => Hash::make('pw')]);
        $bob = User::factory()->create(['password' => Hash::make('pw')]);

        // Both pend a change to the same email.
        $this->actingAs($alice, 'sanctum')->postJson('/api/v1/account/email', [
            'current_password' => 'pw', 'new_email' => 'shared@example.com',
        ])->assertNoContent();
        $this->actingAs($bob, 'sanctum')->postJson('/api/v1/account/email', [
            'current_password' => 'pw', 'new_email' => 'shared@example.com',
        ])->assertNoContent();

        $changes = \App\Models\EmailChange::all();
        $this->assertCount(2, $changes);

        // Capture both tokens by looking at the dispatched mails.
        $tokens = [];
        Mail::assertSent(\App\Mail\EmailChangeMail::class, function ($m) use (&$tokens) {
            $tokens[$m->changeId] = $m->token;
            return true;
        });

        // Alice confirms first — succeeds.
        $aliceChange = $changes->where('user_id', $alice->id)->first();
        $this->postJson('/api/v1/account/email/confirm', [
            'id' => $aliceChange->id,
            'token' => $tokens[$aliceChange->id],
        ])->assertOk();

        // Bob confirms second — should NOT 500. Returns 409.
        $bobChange = $changes->where('user_id', $bob->id)->first();
        $this->postJson('/api/v1/account/email/confirm', [
            'id' => $bobChange->id,
            'token' => $tokens[$bobChange->id],
        ])->assertStatus(409);
    }
}
