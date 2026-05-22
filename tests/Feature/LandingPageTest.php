<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => '']);
    }

    public function test_root_renders_blade_landing_for_mnsa_safe(): void
    {
        $res = $this->get('/?brand=mnsa-safe');
        $res->assertOk();
        $res->assertSee('<title>Make Netflix Safe Again</title>', false);
        $res->assertSee('data-brand="mnsa-safe"', false);
        $res->assertSee('css/mnsa-landing.css');                       // stylesheet linked
        $res->assertSee('property="og:title"', false);
        $res->assertSee('rel="canonical"', false);
        $res->assertSee('makenetflixsafeagain.com', false);            // canonical URL host
    }

    public function test_root_renders_blade_landing_for_mnsa_straight(): void
    {
        $res = $this->get('/?brand=mnsa-straight');
        $res->assertOk();
        $res->assertSee('<title>Make Netflix Straight Again</title>', false);
        $res->assertSee('data-brand="mnsa-straight"', false);
        $res->assertSee('makenetflixstraightagain.com', false);
    }

    public function test_root_serves_spa_for_sponge_kids(): void
    {
        File::ensureDirectoryExists(public_path('build'));
        $existed = File::exists(public_path('build/index.html'));
        if (! $existed) {
            File::put(public_path('build/index.html'), '<!doctype html><div id="root">SPA</div>');
        }

        $res = $this->get('http://sponge.kids/');
        $res->assertOk();
        $res->assertSee('id="root"', false);
        $res->assertDontSee('<title>Make Netflix', false);

        if (! $existed) {
            File::delete(public_path('build/index.html'));
        }
    }

    public function test_app_links_are_relative_when_frontend_url_is_unset(): void
    {
        // Production setup: Laravel serves the SPA from the same origin, so the
        // landing's /register, /login links stay relative.
        $res = $this->get('/?brand=mnsa-safe');
        $res->assertOk();
        $res->assertSee('href="/register"', false);
        $res->assertSee('href="/login"', false);
        $res->assertDontSee('localhost:5173', false);   // not pointed at the dev SPA
    }

    public function test_app_links_use_frontend_url_and_carry_brand_in_local_dev(): void
    {
        config(['app.frontend_url' => 'http://localhost:5173', 'app.env' => 'local']);
        $res = $this->get('/?brand=mnsa-straight');
        $res->assertOk();
        $res->assertSee('href="http://localhost:5173/register?brand=mnsa-straight"', false);
        $res->assertSee('href="http://localhost:5173/login?brand=mnsa-straight"', false);
        $res->assertDontSee('href="/register"', false);   // no stray relative app link
    }

    public function test_landing_renders_nav_and_hero(): void
    {
        $res = $this->get('/?brand=mnsa-safe');
        $res->assertOk();
        $res->assertSee('class="nav"', false);
        $res->assertSee('class="brand__mark"', false);
        $res->assertSee('href="/register"', false);
        $res->assertSee('href="/login"', false);
        $res->assertSee('class="hero"', false);
        $res->assertSee('class="poster-grid"', false);
        $res->assertSee('id="hero-title"', false);
        $res->assertSee('href="#demo"', false);
        // hero copy from the baseline
        $res->assertSee('Lorem ipsum dolor sit amet, consectetur adipiscing elit. Integer pretium');
    }

    public function test_landing_renders_problem_and_demo(): void
    {
        $res = $this->get('/?brand=mnsa-safe');
        $res->assertOk();
        $res->assertSee('id="problem"', false);
        $res->assertSee('class="argument-grid"', false);
        $res->assertSee('01 · Lorem');
        $res->assertSee('id="demo"', false);
        $res->assertSee('class="restriction-window"', false);
        $res->assertSee('class="extension-panel"', false);
        $res->assertSee('class="candidate"', false);
        $res->assertSee('Lorem ipsum (2)');                 // installCta + count
    }

    public function test_landing_renders_remaining_sections(): void
    {
        $res = $this->get('/?brand=mnsa-safe');
        $res->assertOk();
        $res->assertSee('id="how"', false);
        $res->assertSee('class="step__number"', false);
        $res->assertSee('id="features"', false);
        $res->assertSee('class="feature-strip"', false);
        $res->assertSee('id="faq"', false);
        $res->assertSee('<details open>', false);                       // first FAQ item open
        $res->assertSee('<summary>Lorem ipsum dolor sit amet?</summary>', false);
        $res->assertSee('id="install"', false);
        $res->assertSee('class="footer"', false);
        $res->assertSee('class="disclaimer"', false);
    }
}
