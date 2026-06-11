<?php

namespace Tests\Unit\Services\NetflixKids;

use App\Services\NetflixKids\NetflixKidsClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NetflixKidsClientTest extends TestCase
{
    private function configure(): void
    {
        config()->set('services.netflix_kids.cookie', 'NetflixId=abc; SecureNetflixId=def');
        config()->set('services.netflix_kids.persisted_query_id', 'pq-id');
        config()->set('services.netflix_kids.persisted_query_version', 102);
        config()->set('services.netflix_kids.maturity_ceiling', 70);
        config()->set('services.netflix_kids.search_delay', 0);
    }

    public function test_maturity_levels_batches_and_maps_ids(): void
    {
        $this->configure();
        Http::fake(['*pathEvaluator*' => Http::response([
            'jsonGraph' => ['videos' => [
                '111' => ['maturity' => ['$type' => 'atom', 'value' => ['rating' => ['maturityLevel' => 70]]]],
                '222' => ['maturity' => ['$type' => 'atom', 'value' => ['rating' => ['maturityLevel' => 110]]]],
            ]],
        ], 200)]);

        $levels = (new NetflixKidsClient)->maturityLevels(
            [111, 222],
            'https://www.netflix.com/nq/website/memberapi/release',
            'auth-token'
        );

        $this->assertSame(70, $levels[111]);
        $this->assertSame(110, $levels[222]);
        Http::assertSent(fn ($r) => str_starts_with(
            $r->url(),
            'https://www.netflix.com/nq/website/memberapi/release/pathEvaluator?original_path=%2Fshakti%2Fmre%2FpathEvaluator'
        ));
    }

    public function test_probe_session_scrapes_country_kids_auth_and_build(): void
    {
        $this->configure();
        $html = '<html><body data-uia="container-kids">'
            .'"countryOfSignup":"US","currentCountry":"US",'
            .'"authURL":"c1.123.AgiMlOvcAxIg\x2BfooBar\x3D\x3D",'
            .'"apiUrl":"https:\x2F\x2Fwww.netflix.com\x2Fapi\x2Fshakti\x2Fmre",'
            .'"memberapi":{"protocol":"https","hostname":"www.netflix.com",'
            .'"path":["\x2Fnq\x2Fwebsite\x2Fmemberapi\x2Frelease"],"isNodequark":true},'
            .'"BUILD_IDENTIFIER":"v6c030968"</body></html>';
        Http::fake(['www.netflix.com/Kids' => Http::response($html, 200)]);

        $s = (new NetflixKidsClient)->probeSession();

        $this->assertSame('US', $s['country']);
        $this->assertTrue($s['is_kids']);
        $this->assertSame('c1.123.AgiMlOvcAxIg+fooBar==', $s['auth_url']);
        $this->assertSame('https://www.netflix.com/nq/website/memberapi/release', $s['member_api_url']);
        $this->assertSame('v6c030968', $s['app_version']);
    }

    public function test_search_has_id_true_when_id_in_results(): void
    {
        $this->configure();
        Http::fake(['web.prod.cloud.netflix.com/graphql' => Http::response(
            '{"data":{"search":{"edges":[{"node":{"videoId":683101}},{"node":{"videoId":999}}]}}}', 200
        )]);
        $c = new NetflixKidsClient;
        $this->assertTrue($c->searchHasId('land before time', 683101, 'v6c030968'));
        $this->assertFalse($c->searchHasId('seinfeld', 70153373, 'v6c030968'));
    }

    public function test_search_throws_on_persistent_http_error_instead_of_silent_false(): void
    {
        $this->configure();
        config()->set('services.netflix_kids.retry_times', 2);
        config()->set('services.netflix_kids.retry_sleep_ms', 0);
        Http::fake(['web.prod.cloud.netflix.com/graphql' => Http::response('rate limited', 429)]);

        // A persistent 429 must surface as an exception (so the command skips the
        // title), NOT a silent false that would mark it "not in Kids".
        $this->expectException(\RuntimeException::class);
        (new NetflixKidsClient)->searchHasId('whatever', 123, 'v6c030968');
    }

    public function test_maturity_throws_on_persistent_http_error(): void
    {
        $this->configure();
        config()->set('services.netflix_kids.retry_times', 2);
        config()->set('services.netflix_kids.retry_sleep_ms', 0);
        Http::fake(['*pathEvaluator*' => Http::response('upstream error', 503)]);

        $this->expectException(\RuntimeException::class);
        (new NetflixKidsClient)->maturityLevels([111], 'https://www.netflix.com/nq/website/memberapi/release', 'auth');
    }
}
