<?php

namespace Tests\Feature\Api\V1\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingManageUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_customer_center_url_with_user_id_substituted(): void
    {
        config()->set('brands.brands.mnsa-safe.customer_center_url_pattern', 'https://pay.rev.cat/customer/{appUserId}');
        $user = User::factory()->create();

        $resp = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Brand', 'mnsa-safe')
            ->getJson('/api/v1/billing/manage-url');

        $resp->assertOk()->assertJson([
            'manage_url' => "https://pay.rev.cat/customer/{$user->id}",
        ]);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/billing/manage-url')->assertStatus(401);
    }
}
