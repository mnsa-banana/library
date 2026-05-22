<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BrandWebOverrideTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A web route that echoes the resolved brand key.
        Route::middleware('web')->get('/__brand_probe', fn (\Illuminate\Http\Request $r) => response($r->attributes->get('brand_key', 'NONE')));
    }

    public function test_query_param_overrides_brand_in_testing_env(): void
    {
        $this->get('/__brand_probe?brand=mnsa-straight')->assertOk()->assertSee('mnsa-straight');
        $this->get('/__brand_probe?brand=mnsa-safe')->assertOk()->assertSee('mnsa-safe');
    }

    public function test_unknown_query_brand_is_ignored(): void
    {
        $this->get('/__brand_probe?brand=bogus')->assertOk()->assertSee(config('brands.default'));
    }

    public function test_x_brand_header_still_works_on_web(): void
    {
        $this->withHeaders(['X-Brand' => 'mnsa-safe'])->get('/__brand_probe')->assertOk()->assertSee('mnsa-safe');
    }

    public function test_host_resolution_still_works(): void
    {
        $this->get('http://makenetflixstraightagain.com/__brand_probe')->assertOk()->assertSee('mnsa-straight');
        // a dotted domain must resolve via direct array lookup, not config() dot-notation
        $this->get('http://makenetflixsafeagain.com/__brand_probe')->assertOk()->assertSee('mnsa-safe');
        $this->get('http://sponge.kids/__brand_probe')->assertOk()->assertSee('sponge-kids');
    }
}
