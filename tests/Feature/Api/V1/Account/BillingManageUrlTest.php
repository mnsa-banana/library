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
        $user = User::factory()->create();

        $resp = $this->actingAs($user, 'sanctum')
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
