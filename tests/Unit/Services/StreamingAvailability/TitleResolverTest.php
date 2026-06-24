<?php

namespace Tests\Unit\Services\StreamingAvailability;

use App\Services\StreamingAvailability\TitleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TitleResolverTest extends TestCase
{
    use RefreshDatabase;

    private function title(string $id, string $title, string $show = 'movie', ?int $releaseYear = null, ?int $firstAirYear = null): void
    {
        DB::table('streaming_titles')->insert([
            'id' => $id, 'show_type' => $show, 'title' => $title,
            'release_year' => $releaseYear, 'first_air_year' => $firstAirYear,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_exact_normalized_same_type_match(): void
    {
        $this->title('a', 'The Smurfs', 'movie');
        $r = new TitleResolver;
        $this->assertSame('a', $r->resolve('the smurfs!', 'movie'));
    }

    public function test_containment_variant_match_same_type(): void
    {
        $this->title('b', "We're Lalaloopsy", 'series');
        $r = new TitleResolver;
        $this->assertSame('b', $r->resolve('Lalaloopsy', 'series'));
    }

    public function test_rejects_wrong_type(): void
    {
        $this->title('c', 'Matilda', 'series');
        $r = new TitleResolver;
        $this->assertNull($r->resolve('Matilda', 'movie'));
    }

    public function test_rejects_numeric_sequel(): void
    {
        $this->title('d', 'Frozen 2', 'movie');
        $r = new TitleResolver;
        $this->assertNull($r->resolve('Frozen', 'movie'));
    }

    public function test_returns_null_when_no_match(): void
    {
        $this->title('e', 'Something Else', 'movie');
        $r = new TitleResolver;
        $this->assertNull($r->resolve('Percy Jackson', 'movie'));
    }

    public function test_rejects_subword_containment(): void
    {
        $this->title('f', 'Coconut', 'movie');
        $r = new TitleResolver;
        $this->assertNull($r->resolve('Coco', 'movie'));
    }

    public function test_rejects_ambiguous_multi_match(): void
    {
        $this->title('g', 'Paw Patrol The Movie', 'movie');
        $this->title('h', 'Paw Patrol Jet Force', 'movie');
        $r = new TitleResolver;
        $this->assertNull($r->resolve('Paw Patrol', 'movie'));
    }

    public function test_containment_match_when_query_is_longer_than_db_title(): void
    {
        // DB title is SHORTER than the query; the match must still be found via
        // the token index (Case-B direction: candidate tokens ⊂ query tokens).
        $this->title('i', 'Paw Patrol', 'movie');
        $r = new TitleResolver;
        $this->assertSame('i', $r->resolve('Paw Patrol The Movie', 'movie'));
    }

    public function test_no_match_when_no_shared_token(): void
    {
        // No shared token → token-gather yields an empty candidate set → null.
        $this->title('j', 'Coconut', 'movie');
        $r = new TitleResolver;
        $this->assertNull($r->resolve('Bluey', 'movie'));
    }

    public function test_year_disambiguates_same_name_collision(): void
    {
        // Four "Fearless" movies. Without a year the resolver greedily returned the
        // lexicographically-first id ('13144' = the 1993 adult drama); the Netflix
        // Kids title is the 2020 film ('62957'). The year must pick the right one.
        $this->title('13144', 'Fearless', 'movie', 1993);
        $this->title('5781', 'Fearless', 'movie', 2006);
        $this->title('24595992', 'Fearless', 'movie', 2025);
        $this->title('62957', 'Fearless', 'movie', 2020);
        $r = new TitleResolver;
        $this->assertSame('62957', $r->resolve('Fearless', 'movie', 2020));
    }

    public function test_ambiguous_exact_name_without_year_is_null(): void
    {
        // No year supplied → can't disambiguate a collision → null (logged upstream),
        // never a greedy guess.
        $this->title('13144', 'Fearless', 'movie', 1993);
        $this->title('62957', 'Fearless', 'movie', 2020);
        $r = new TitleResolver;
        $this->assertNull($r->resolve('Fearless', 'movie'));
    }

    public function test_nearest_year_wins_when_no_exact_year_match(): void
    {
        // Year matches neither exactly, but 2020 is far closer to 2011 than 1993 is →
        // the unique-nearest candidate wins (years drift: Netflix platform/season year).
        $this->title('13144', 'Fearless', 'movie', 1993);
        $this->title('62957', 'Fearless', 'movie', 2020);
        $r = new TitleResolver;
        $this->assertSame('62957', $r->resolve('Fearless', 'movie', 2011));
    }

    public function test_equidistant_year_is_null(): void
    {
        // A tie for closest is genuinely ambiguous → null, never a guess.
        $this->title('a', 'Fearless', 'movie', 2018);
        $this->title('b', 'Fearless', 'movie', 2022);
        $r = new TitleResolver;
        $this->assertNull($r->resolve('Fearless', 'movie', 2020));
    }

    public function test_collision_with_all_null_years_is_null(): void
    {
        // Candidates carry no year to compare against → can't disambiguate → null.
        $this->title('a', 'Fearless', 'movie', null);
        $this->title('b', 'Fearless', 'movie', null);
        $r = new TitleResolver;
        $this->assertNull($r->resolve('Fearless', 'movie', 2020));
    }

    public function test_single_exact_match_returns_even_when_year_mismatches(): void
    {
        // A sole same-name match must win; a divergent (or stale/null) DB year must
        // not reject it — the year only breaks ties.
        $this->title('only', 'Bluey', 'series', null, 2018);
        $r = new TitleResolver;
        $this->assertSame('only', $r->resolve('Bluey', 'series', 1999));
    }

    public function test_series_collision_disambiguated_by_first_air_year(): void
    {
        // Series use first_air_year, not release_year.
        $this->title('s1', 'Fearless', 'series', null, 2016);
        $this->title('s2', 'Fearless', 'series', null, 2022);
        $r = new TitleResolver;
        $this->assertSame('s2', $r->resolve('Fearless', 'series', 2022));
    }

    public function test_exact_candidates_lists_same_name_same_type_with_years(): void
    {
        $this->title('13144', 'Fearless', 'movie', 1993);
        $this->title('62957', 'Fearless', 'movie', 2020);
        $this->title('s1', 'Fearless', 'series', null, 2016); // different type → excluded
        $r = new TitleResolver;
        $cands = $r->exactCandidates('Fearless!', 'movie'); // normalization tolerant
        $years = collect($cands)->pluck('year', 'id')->all();
        $this->assertEqualsCanonicalizing(['13144', '62957'], array_keys($years));
        $this->assertSame(1993, $years['13144']);
        $this->assertSame(2020, $years['62957']);
    }

    public function test_exact_candidates_empty_when_no_exact_name(): void
    {
        $this->title('a', 'Coconut', 'movie');
        $r = new TitleResolver;
        $this->assertSame([], $r->exactCandidates('Coco', 'movie'));
    }
}
