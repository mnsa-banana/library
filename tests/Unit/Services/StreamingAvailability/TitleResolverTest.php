<?php

namespace Tests\Unit\Services\StreamingAvailability;

use App\Services\StreamingAvailability\TitleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TitleResolverTest extends TestCase
{
    use RefreshDatabase;

    private function title(string $id, string $title, string $show = 'movie'): void
    {
        DB::table('streaming_titles')->insert([
            'id' => $id, 'show_type' => $show, 'title' => $title,
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
}
