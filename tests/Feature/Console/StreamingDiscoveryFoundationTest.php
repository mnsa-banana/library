<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StreamingDiscoveryFoundationTest extends TestCase
{
    use RefreshDatabase;

    /** Seed a title + service so an offer row's FKs resolve. */
    private function seedTitle(string $id, string $title = 'A Title'): void
    {
        DB::table('streaming_services')->updateOrInsert(['id' => 'netflix'], ['name' => 'Netflix']);
        DB::table('streaming_titles')->updateOrInsert(
            ['id' => $id],
            ['show_type' => 'movie', 'title' => $title, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function test_offer_source_defaults_to_motn(): void
    {
        $this->seedTitle('t1');
        DB::table('streaming_title_offers')->insert([
            'title_id' => 't1', 'service_id' => 'netflix', 'region' => 'US',
            'type' => 'subscription', 'link' => 'https://www.netflix.com/title/1/', 'updated_at' => now(),
        ]);

        $this->assertSame('motn',
            DB::table('streaming_title_offers')->where('title_id', 't1')->value('source'));
    }
}
