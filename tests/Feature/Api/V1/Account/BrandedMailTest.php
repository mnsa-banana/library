<?php

namespace Tests\Feature\Api\V1\Account;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BrandedMailTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('brandProvider')]
    public function test_password_reset_mail_uses_brand_from_request_header(string $brandKey, string $expectedName): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'parent@example.com']);

        $this->withHeader('X-Brand', $brandKey)
            ->postJson('/api/v1/password/forgot', ['email' => 'parent@example.com'])
            ->assertNoContent();

        Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use ($brandKey, $expectedName) {
            return $mail->brand->key === $brandKey
                && $mail->brand->name === $expectedName;
        });
    }

    public static function brandProvider(): array
    {
        return [
            ['sponge-kids', 'Sponge Kids'],
            ['mnsa-safe', 'Make Netflix Safe Again'],
            ['mnsa-straight', 'Make Netflix Straight Again'],
        ];
    }

    public function test_same_user_three_brands_three_different_mails(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'parent@example.com']);

        foreach (['sponge-kids', 'mnsa-safe', 'mnsa-straight'] as $brand) {
            $this->withHeader('X-Brand', $brand)
                ->postJson('/api/v1/password/forgot', ['email' => 'parent@example.com'])
                ->assertNoContent();
        }

        Mail::assertSentCount(3);
        $brandsSeen = [];
        Mail::assertSent(ResetPasswordMail::class, function ($m) use (&$brandsSeen) {
            $brandsSeen[] = $m->brand->key;
            return true;
        });
        sort($brandsSeen);
        $this->assertSame(['mnsa-safe', 'mnsa-straight', 'sponge-kids'], $brandsSeen);
    }
}
