<?php

namespace Tests\Feature\Api\V1\Account;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_email_sends_reset_mail_and_returns_204(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'parent@example.com']);

        $resp = $this->withHeader('X-Brand', 'mnsa-safe')
            ->postJson('/api/v1/password/forgot', ['email' => 'parent@example.com']);

        $resp->assertNoContent();
        Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->brand->key === 'mnsa-safe'
                && ! empty($mail->token);
        });
    }

    public function test_unknown_email_returns_204_without_sending_mail(): void
    {
        Mail::fake();

        $resp = $this->postJson('/api/v1/password/forgot', ['email' => 'nobody@example.com']);

        $resp->assertNoContent();
        Mail::assertNothingSent();
    }

    public function test_persists_a_password_reset_token_row(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'parent@example.com']);

        $this->postJson('/api/v1/password/forgot', ['email' => 'parent@example.com'])
            ->assertNoContent();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'parent@example.com']);
    }

    public function test_rate_limits_after_3_requests_per_minute_for_same_email(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'parent@example.com']);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/password/forgot', ['email' => 'parent@example.com'])->assertNoContent();
        }

        $this->postJson('/api/v1/password/forgot', ['email' => 'parent@example.com'])
            ->assertStatus(429);
    }
}
