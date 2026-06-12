<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReadAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['library.read_tokens' => 'mnsa:mnsa-token,sponge:sponge-token,admin:admin-token']);

        // A throwaway probe route lets these tests pin the middleware contract
        // itself, independent of which real endpoints exist.
        Route::middleware('verify.read-token')->get('/api/v1/_auth-probe', fn () => response()->json(['ok' => true]));
    }

    public function test_401_without_a_token(): void
    {
        $this->getJson('/api/v1/_auth-probe')->assertStatus(401);
    }

    public function test_401_with_an_unknown_token(): void
    {
        $this->withToken('wrong')->getJson('/api/v1/_auth-probe')->assertStatus(401);
    }

    public function test_each_named_token_works(): void
    {
        foreach (['mnsa-token', 'sponge-token', 'admin-token'] as $token) {
            $this->withToken($token)->getJson('/api/v1/_auth-probe')->assertOk();
        }
    }

    public function test_401_when_no_tokens_are_configured(): void
    {
        config(['library.read_tokens' => '']);

        $this->withToken('anything')->getJson('/api/v1/_auth-probe')->assertStatus(401);
    }

    public function test_tolerates_whitespace_and_malformed_pairs(): void
    {
        config(['library.read_tokens' => ' mnsa:mnsa-token , bare-token-without-name,, sponge:sponge-token ']);

        $this->withToken('mnsa-token')->getJson('/api/v1/_auth-probe')->assertOk();
        $this->withToken('sponge-token')->getJson('/api/v1/_auth-probe')->assertOk();
        $this->withToken('bare-token-without-name')->getJson('/api/v1/_auth-probe')->assertStatus(401);
    }
}
