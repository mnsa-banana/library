<?php

namespace Tests\Unit\Support;

use Tests\TestCase;

class LandingCopyConfigTest extends TestCase
{
    public function test_baseline_has_both_brands_with_required_sections(): void
    {
        foreach (['mnsa-safe', 'mnsa-straight'] as $key) {
            $b = config("mnsa_landing.brands.$key");
            $this->assertIsArray($b, "baseline missing for $key");
            foreach (['meta', 'brandNameLead', 'brandNameAccent', 'nav', 'hero', 'problem', 'demo', 'workflow', 'features', 'faq', 'cta', 'footer'] as $section) {
                $this->assertArrayHasKey($section, $b, "$key baseline missing '$section'");
            }
            $this->assertArrayHasKey('title', $b['meta']);
            $this->assertArrayHasKey('description', $b['meta']);
            $this->assertArrayHasKey('ogImage', $b['meta']);
            $this->assertCount(3, $b['problem']['arguments']);
            $this->assertCount(5, $b['demo']['window']['items']);
            $this->assertCount(2, $b['demo']['panel']['candidates']);
            $this->assertCount(3, $b['workflow']['steps']);
            $this->assertCount(4, $b['features']['items']);
            $this->assertCount(3, $b['faq']['items']);
        }
        $this->assertSame('Safe Again', config('mnsa_landing.brands.mnsa-safe.brandNameAccent'));
        $this->assertSame('Straight Again', config('mnsa_landing.brands.mnsa-straight.brandNameAccent'));
        $this->assertSame('Make Netflix Safe Again', config('mnsa_landing.brands.mnsa-safe.meta.title'));
    }

}
