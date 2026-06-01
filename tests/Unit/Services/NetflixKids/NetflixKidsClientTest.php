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

    public function test_probe_session_scrapes_country_kids_auth_and_build(): void
    {
        $this->configure();
        $html = '<html><body data-uia="container-kids">'
            . '"countryOfSignup":"US","currentCountry":"US",'
            . '"authURL":"c1.123.AgiMlOvcAxIg\x2BfooBar\x3D\x3D",'
            . '"apiUrl":"https:\x2F\x2Fwww.netflix.com\x2Fapi\x2Fshakti\x2Fmre",'
            . '"BUILD_IDENTIFIER":"v6c030968"</body></html>';
        Http::fake(['www.netflix.com/Kids' => Http::response($html, 200)]);

        $s = (new NetflixKidsClient())->probeSession();

        $this->assertSame('US', $s['country']);
        $this->assertTrue($s['is_kids']);
        $this->assertSame('c1.123.AgiMlOvcAxIg+fooBar==', $s['auth_url']);
        $this->assertSame('https://www.netflix.com/api/shakti/mre', $s['shakti_url']);
        $this->assertSame('v6c030968', $s['app_version']);
    }
}
