<?php

namespace Tests\Feature\Console;

use App\Models\BookListMembership;
use App\Models\BookSyncLog;
use App\Services\BookLibrary\CsmIndexScraper;
use App\Services\BookLibrary\PluggedInIndexScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const CSM = 'https://www.commonsensemedia.org';

    private const PI = 'https://www.pluggedin.com';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app->instance(CsmIndexScraper::class, new CsmIndexScraper(delayMs: 0));
        $this->app->instance(PluggedInIndexScraper::class, new PluggedInIndexScraper(delayMs: 0));
        // No NYT key: book:weekly must no-op gracefully inside the pipeline.
        config(['services.nyt.books_key' => null]);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/fixtures/book_library/{$name}"));
    }

    /** Both index walks faked; every walked URL pre-seeded → pure no-op deltas. */
    private function fakeAllKnown(): void
    {
        Http::fake([
            self::CSM.'/sitemap.xml' => Http::response($this->fixture('csm_sitemap_index.xml')),
            self::CSM.'/reviews/sitemap.xml?page=1' => Http::response($this->fixture('csm_reviews_page1.xml')),
            self::CSM.'/reviews/sitemap.xml' => Http::response($this->fixture('csm_reviews_sitemapindex.xml')),
            self::PI.'/sitemap_index.xml' => Http::response($this->fixture('pluggedin_sitemap_index.xml')),
            self::PI.'/book-reviews-sitemap.xml' => Http::response($this->fixture('pluggedin_book_reviews_sitemap.xml')),
            self::PI.'/book-reviews-sitemap2.xml' => Http::response($this->fixture('pluggedin_book_reviews_sitemap2.xml')),
        ]);

        foreach (['a-wrinkle-in-time', 'charlottes-web', 'the-wild-robot'] as $slug) {
            BookListMembership::factory()->create([
                'list_source' => 'csm_index',
                'review_url' => self::CSM."/book-reviews/{$slug}",
            ]);
        }
        foreach (['bravely', 'freedom-train-the-story-of-harriet-tubman', 'higher-power-of-lucky'] as $slug) {
            BookListMembership::factory()->create([
                'list_source' => 'pluggedin_index',
                'review_url' => self::PI."/book-reviews/{$slug}/",
            ]);
        }
    }

    public function test_runs_both_deltas_and_weekly_with_one_flagless_command(): void
    {
        $this->fakeAllKnown();

        $this->artisan('book:update')->assertExitCode(0);

        // Only the cheap sitemap walks fired — no review-page fetches, and
        // no NYT calls (key unset → weekly no-ops with a visible log row).
        $paths = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->url());
        $this->assertSame([], $paths->filter(fn (string $u) => str_contains($u, '/book-reviews/'))->values()->all());
        $this->assertSame([], $paths->filter(fn (string $u) => str_contains($u, 'nytimes.com'))->values()->all());

        $types = BookSyncLog::orderBy('id')->pluck('status', 'sync_type')->all();
        $this->assertSame('completed', $types['seed_csm']);
        $this->assertSame('completed', $types['seed_pluggedin']);
        $this->assertSame('failed', $types['weekly']); // no-key marker row, exit still 0
    }

    public function test_fails_fast_when_a_step_fails(): void
    {
        // CSM page sitemap yields zero book-review URLs → seed_csm fails →
        // the pipeline aborts before any Plugged In request fires.
        Http::fake([
            self::CSM.'/sitemap.xml' => Http::response($this->fixture('csm_sitemap_index.xml')),
            self::CSM.'/reviews/sitemap.xml?page=1' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>'
            ),
            self::CSM.'/reviews/sitemap.xml' => Http::response($this->fixture('csm_reviews_sitemapindex.xml')),
        ]);

        $this->artisan('book:update')->assertExitCode(1);

        $paths = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->url());
        $this->assertSame([], $paths->filter(fn (string $u) => str_contains($u, 'pluggedin.com'))->values()->all());

        $this->assertSame('failed', BookSyncLog::where('sync_type', 'seed_csm')->sole()->status);
        $this->assertSame(0, BookSyncLog::where('sync_type', 'seed_pluggedin')->count());
    }
}
