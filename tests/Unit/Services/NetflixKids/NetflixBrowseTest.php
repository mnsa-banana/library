<?php

namespace Tests\Unit\Services\NetflixKids;

use App\Services\NetflixKids\NetflixKidsClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NetflixBrowseTest extends TestCase
{
    private function cfg(): void
    {
        config()->set('services.netflix_kids.cookie', 'NetflixId=abc');
        config()->set('services.netflix_kids.retry_sleep_ms', 0);
    }

    public function test_browse_genre_walks_pages_until_empty(): void
    {
        $this->cfg();
        // Page 1 (from=0): 50 refs; page 2 (from=50): 1 ref; page 3: none → stop.
        $page1 = '{"jsonGraph":{"genres":{"34399":{"su":{'
            .implode(',', array_map(fn ($i) => '"'.$i.'":{"reference":{"$type":"ref","value":["videos","'.(1000 + $i).'"]}}', range(0, 49)))
            .'}}}}}';
        $page2 = '{"jsonGraph":{"genres":{"34399":{"su":{"50":{"reference":{"$type":"ref","value":["videos","2000"]}}}}}}}';

        Http::fakeSequence()->push($page1, 200)->push($page2, 200);

        $ids = (new NetflixKidsClient)->browseGenreVideoIds(34399, 'https://www.netflix.com/nq/website/memberapi/release', 'auth');

        $this->assertCount(51, $ids);
        $this->assertContains(1000, $ids);
        $this->assertContains(2000, $ids);
    }

    public function test_resolve_video_titles_batches_and_maps(): void
    {
        $this->cfg();
        // Title 1000 carries a releaseYear atom; 2000 omits it (year is best-effort).
        Http::fake([
            '*pathEvaluator*' => Http::response('{"jsonGraph":{"videos":{'
                .'"1000":{"title":{"$type":"atom","value":"Bee Movie"},"releaseYear":{"$type":"atom","value":2007}},'
                .'"2000":{"title":{"$type":"atom","value":"Minions"}}}}}', 200),
        ]);

        $titles = (new NetflixKidsClient)->resolveVideoTitles([1000, 2000], 'https://www.netflix.com/nq/website/memberapi/release', 'auth');

        $this->assertSame('Bee Movie', $titles[1000]['title']);
        $this->assertSame(2007, $titles[1000]['year']);
        $this->assertSame('Minions', $titles[2000]['title']);
        $this->assertNull($titles[2000]['year']);
    }

    public function test_maturity_levels_returns_id_to_level_map(): void
    {
        $this->cfg();
        // Proves the memberFalcor refactor of maturityLevels() is behavior-preserving:
        // falcor atom payload nests under value.rating.maturityLevel.
        Http::fake([
            '*pathEvaluator*' => Http::response('{"jsonGraph":{"videos":{'
                .'"1000":{"maturity":{"$type":"atom","value":{"rating":{"maturityLevel":70}}}},'
                .'"2000":{"maturity":{"$type":"atom","value":{"rating":{"maturityLevel":100}}}}}}}', 200),
        ]);

        $levels = (new NetflixKidsClient)->maturityLevels([1000, 2000], 'https://www.netflix.com/nq/website/memberapi/release', 'auth');

        $this->assertSame(70, $levels[1000]);
        $this->assertSame(100, $levels[2000]);
    }
}
