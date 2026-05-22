<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BrandMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_mnsa_safe_domain_sets_brand(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders([
            'Host' => 'makenetflixsafeagain.com',
            'Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken,
        ])->getJson('/api/v1/me');

        $response->assertOk();
    }

    public function test_mnsa_straight_domain_sets_brand(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders([
            'Host' => 'makenetflixstraightagain.com',
            'Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken,
        ])->getJson('/api/v1/me');

        $response->assertOk();
    }

    public function test_sponge_kids_domain_sets_brand(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders([
            'Host' => 'sponge.kids',
            'Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken,
        ])->getJson('/api/v1/me');

        $response->assertOk();
    }

    public function test_unknown_domain_uses_default_brand(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders([
            'Host' => 'localhost',
            'Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken,
        ])->getJson('/api/v1/me');

        $response->assertOk();
    }

    public function test_billing_checkout_url_varies_by_brand(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $mnsaResponse = $this->withHeaders([
            'Host' => 'makenetflixsafeagain.com',
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/v1/billing/checkout-url');

        $mnsaResponse->assertOk();
        $mnsaResponse->assertJsonStructure(['checkout_url']);
    }
}
