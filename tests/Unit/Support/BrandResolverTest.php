<?php

namespace Tests\Unit\Support;

use App\Support\BrandResolver;
use Illuminate\Http\Request;
use Tests\TestCase;

class BrandResolverTest extends TestCase
{
    public function test_resolves_brand_from_x_brand_header(): void
    {
        $request = Request::create('/api/v1/anything', 'GET');
        $request->headers->set('X-Brand', 'mnsa-safe');

        $brand = (new BrandResolver())->fromRequest($request);

        $this->assertSame('mnsa-safe', $brand->key);
        $this->assertSame('Make Netflix Safe Again', $brand->name);
        $this->assertSame('#e23636', $brand->accentHex);
    }

    public function test_resolves_brand_from_host_when_no_header(): void
    {
        $request = Request::create('https://sponge.kids/foo', 'GET');

        $brand = (new BrandResolver())->fromRequest($request);

        $this->assertSame('sponge-kids', $brand->key);
    }

    public function test_falls_back_to_default_brand_when_unrecognized(): void
    {
        $request = Request::create('https://unknown.example/foo', 'GET');

        $brand = (new BrandResolver())->fromRequest($request);

        $this->assertSame(config('brands.default'), $brand->key);
    }

    public function test_from_key_returns_known_brand(): void
    {
        $brand = (new BrandResolver())->fromKey('mnsa-straight');
        $this->assertSame('Make Netflix Straight Again', $brand->name);
    }

    public function test_from_key_falls_back_for_unknown(): void
    {
        $brand = (new BrandResolver())->fromKey('does-not-exist');
        $this->assertSame(config('brands.default'), $brand->key);
    }
}
