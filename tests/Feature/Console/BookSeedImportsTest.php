<?php

namespace Tests\Feature\Console;

use App\Models\BookLibraryTitle;
use App\Models\BookSyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * File-import seed arms: --source=wkar and --source=award. Neither arm carries
 * ISBNs, so the resolver never calls Open Library — no HTTP at all.
 */
class BookSeedImportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    /** @return string path to a throwaway JSON file with the given content */
    private function tempJson(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'book_seed_test_').'.json';
        file_put_contents($path, $content);

        return $path;
    }

    // ── WKAR ────────────────────────────────────────────────────────────────

    public function test_wkar_import_creates_titles_with_grade_band_metadata_and_min_age(): void
    {
        $this->artisan('book:seed', [
            '--source' => 'wkar',
            '--file' => base_path('tests/fixtures/book_library/wkar_sample.json'),
        ])->assertExitCode(0);

        $this->assertSame(5, BookLibraryTitle::count());

        // Grade-band → min_age mapping: K-2→5, 3-5→8, 6-8→11, 9-12→14.
        foreach ([
            'The Pigeon Has to Go to School!' => [5, 'K-2'],
            'Dog Man: Mothering Heights' => [8, '3-5'],
            'Diary of a Wimpy Kid' => [11, '6-8'],
            'The Outsiders' => [14, '9-12'],
        ] as $titleText => [$minAge, $band]) {
            $title = BookLibraryTitle::where('title', $titleText)->sole();
            $this->assertSame($minAge, $title->min_age, $titleText);
            $this->assertSame('wkar', $title->min_age_source, $titleText);

            $membership = $title->memberships()->sole();
            $this->assertSame('wkar', $membership->list_source);
            $this->assertSame('2024', $membership->list_key);
            $this->assertSame(1, $membership->rank);
            $this->assertSame(['grade_band' => $band], $membership->metadata);
        }

        $log = BookSyncLog::sole();
        $this->assertSame('seed_wkar', $log->sync_type);
        $this->assertSame('completed', $log->status);
        $this->assertSame(5, $log->titles_processed);
        $this->assertTrue($log->metadata['exhausted']);

        Http::assertNothingSent();
    }

    public function test_wkar_does_not_downgrade_a_higher_precedence_min_age(): void
    {
        // csm_index outranks wkar — the import must not overwrite it.
        BookLibraryTitle::create([
            'title' => 'The Outsiders',
            'author' => 'S.E. Hinton',
            'min_age' => 12,
            'min_age_source' => 'csm_index',
        ]);

        $this->artisan('book:seed', [
            '--source' => 'wkar',
            '--file' => base_path('tests/fixtures/book_library/wkar_sample.json'),
        ])->assertExitCode(0);

        $title = BookLibraryTitle::where('title', 'The Outsiders')->sole();
        $this->assertSame(12, $title->min_age);
        $this->assertSame('csm_index', $title->min_age_source);
        // ...but the wkar membership is still recorded.
        $this->assertSame('wkar', $title->memberships()->sole()->list_source);
    }

    public function test_wkar_limit_stops_after_n_entries(): void
    {
        $this->artisan('book:seed', [
            '--source' => 'wkar',
            '--file' => base_path('tests/fixtures/book_library/wkar_sample.json'),
            '--limit' => 2,
        ])->assertExitCode(0);

        $this->assertSame(2, BookLibraryTitle::count());

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
    }

    public function test_wkar_skips_invalid_entries_and_maps_unknown_grade_band_to_null_min_age(): void
    {
        $file = $this->tempJson(json_encode([
            ['title' => 'Valid Book', 'author' => 'Some Author', 'grade_band' => 'K-2', 'year' => 2023],
            ['author' => 'No Title', 'grade_band' => '3-5', 'year' => 2023],
            ['title' => 'No Year Book', 'author' => 'Another Author', 'grade_band' => '3-5'],
            ['title' => 'Odd Band Book', 'author' => 'Third Author', 'grade_band' => 'PreK', 'year' => 2023],
        ]));

        $this->artisan('book:seed', ['--source' => 'wkar', '--file' => $file])->assertExitCode(0);

        $this->assertSame(2, BookLibraryTitle::count());

        $odd = BookLibraryTitle::where('title', 'Odd Band Book')->sole();
        $this->assertNull($odd->min_age);
        $this->assertNull($odd->min_age_source);
        $this->assertSame(['grade_band' => 'PreK'], $odd->memberships()->sole()->metadata);

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertSame(2, $log->titles_processed);
    }

    public function test_wkar_without_file_fails_run_and_exits_one(): void
    {
        $this->artisan('book:seed', ['--source' => 'wkar'])->assertExitCode(1);

        $log = BookSyncLog::sole();
        $this->assertSame('seed_wkar', $log->sync_type);
        $this->assertSame('failed', $log->status);
        Http::assertNothingSent();
    }

    public function test_wkar_with_missing_file_fails_run_and_exits_one(): void
    {
        $this->artisan('book:seed', [
            '--source' => 'wkar',
            '--file' => '/nonexistent/wkar.json',
        ])->assertExitCode(1);

        $this->assertSame('failed', BookSyncLog::sole()->status);
    }

    public function test_wkar_with_non_array_json_fails_run_and_exits_one(): void
    {
        $file = $this->tempJson('{"not": "a list"}');

        $this->artisan('book:seed', ['--source' => 'wkar', '--file' => $file])->assertExitCode(1);

        $this->assertSame('failed', BookSyncLog::sole()->status);
        $this->assertSame(0, BookLibraryTitle::count());
    }

    // ── Award ───────────────────────────────────────────────────────────────

    public function test_award_import_seeds_newbery_canon_with_year_and_type_metadata(): void
    {
        $this->artisan('book:seed', [
            '--source' => 'award',
            '--file' => base_path('database/data/book_library/awards/newbery.json'),
        ])->assertExitCode(0);

        // Spot checks verified against the ALA Newbery medal-and-honors list.
        $first = BookLibraryTitle::where('title', 'The Story of Mankind')->sole();
        $this->assertSame('Hendrik Willem van Loon', $first->author);
        $membership = $first->memberships()->sole();
        $this->assertSame('award', $membership->list_source);
        $this->assertSame('newbery', $membership->list_key);
        $this->assertSame(['year' => 1922, 'type' => 'winner'], $membership->metadata);

        $latest = BookLibraryTitle::where('title', 'All the Blues in the Sky')->sole();
        $this->assertSame('Renée Watson', $latest->author);
        $this->assertSame(
            ['year' => 2026, 'type' => 'winner'],
            $latest->memberships()->sole()->metadata
        );

        $honor = BookLibraryTitle::where('title', "Charlotte's Web")->sole();
        $this->assertSame(
            ['year' => 1953, 'type' => 'honor'],
            $honor->memberships()->sole()->metadata
        );

        $log = BookSyncLog::sole();
        $this->assertSame('seed_award', $log->sync_type);
        $this->assertSame('completed', $log->status);
        $this->assertTrue($log->metadata['exhausted']);

        Http::assertNothingSent();
    }

    public function test_award_list_key_derives_from_the_file_slug(): void
    {
        $this->artisan('book:seed', [
            '--source' => 'award',
            '--file' => base_path('database/data/book_library/awards/printz.json'),
            '--limit' => 5,
        ])->assertExitCode(0);

        $this->assertSame(5, BookLibraryTitle::count());
        BookLibraryTitle::with('memberships')->get()->each(function (BookLibraryTitle $title) {
            $this->assertSame('printz', $title->memberships->sole()->list_key);
        });

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
    }

    public function test_award_skips_entries_with_invalid_type(): void
    {
        $file = $this->tempJson(json_encode([
            ['title' => 'Real Winner', 'author' => 'An Author', 'year' => 2001, 'type' => 'winner'],
            ['title' => 'Bad Type', 'author' => 'Someone', 'year' => 2001, 'type' => 'finalist'],
            ['title' => 'No Year', 'author' => 'Someone Else', 'type' => 'honor'],
        ]));

        $this->artisan('book:seed', ['--source' => 'award', '--file' => $file])->assertExitCode(0);

        $this->assertSame(1, BookLibraryTitle::count());
        $this->assertSame('completed', BookSyncLog::sole()->status);
    }

    public function test_award_without_file_fails_run_and_exits_one(): void
    {
        $this->artisan('book:seed', ['--source' => 'award'])->assertExitCode(1);

        $log = BookSyncLog::sole();
        $this->assertSame('seed_award', $log->sync_type);
        $this->assertSame('failed', $log->status);
    }

    // ── Bundled award data fidelity ─────────────────────────────────────────

    public function test_bundled_award_files_parse_and_carry_complete_well_formed_entries(): void
    {
        $expectations = [
            'newbery' => ['first_year' => 1922, 'latest_year' => 2026],
            'caldecott' => ['first_year' => 1938, 'latest_year' => 2026],
            'printz' => ['first_year' => 2000, 'latest_year' => 2026],
        ];

        foreach ($expectations as $slug => $bounds) {
            $path = base_path("database/data/book_library/awards/{$slug}.json");
            $this->assertFileExists($path);

            $entries = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($entries, $slug);
            $this->assertNotEmpty($entries, $slug);

            $years = [];
            $winnersByYear = [];
            foreach ($entries as $i => $entry) {
                $context = "{$slug}[{$i}]";
                $this->assertIsString($entry['title'] ?? null, $context);
                $this->assertNotSame('', trim($entry['title']), $context);
                $this->assertIsString($entry['author'] ?? null, $context);
                $this->assertNotSame('', trim($entry['author']), $context);
                $this->assertIsInt($entry['year'] ?? null, $context);
                $this->assertContains($entry['type'] ?? null, ['winner', 'honor'], $context);

                $years[] = $entry['year'];
                if ($entry['type'] === 'winner') {
                    $winnersByYear[$entry['year']][] = $entry['title'];
                }
            }

            $this->assertSame($bounds['first_year'], min($years), $slug);
            $this->assertSame($bounds['latest_year'], max($years), $slug);
            // Every listed year has at least one winner (ties are possible).
            foreach (array_unique($years) as $year) {
                $this->assertArrayHasKey($year, $winnersByYear, "{$slug} {$year} has no winner");
            }
        }
    }
}
