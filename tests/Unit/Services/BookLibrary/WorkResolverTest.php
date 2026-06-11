<?php

namespace Tests\Unit\Services\BookLibrary;

use App\Models\BookLibraryTitle;
use App\Services\BookLibrary\OpenLibraryClient;
use App\Services\BookLibrary\WorkResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WorkResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function resolver(): WorkResolver
    {
        return new WorkResolver(new OpenLibraryClient);
    }

    /** Open Library edition payload for /isbn/{isbn}.json fakes. */
    private function olEdition(string $workKey, array $isbn13s = [], ?int $coverId = null): array
    {
        return [
            'works' => [['key' => "/works/{$workKey}"]],
            'isbn_13' => $isbn13s,
            'covers' => $coverId !== null ? [$coverId] : [],
        ];
    }

    // ── Step 1: ISBN match ────────────────────────────────────────────────

    public function test_isbn_hit_with_hyphenated_input_against_stored_digits_only(): void
    {
        Http::fake();
        $row = BookLibraryTitle::factory()->create([
            'title' => 'The Lion, the Witch and the Wardrobe',
            'author' => 'C. S. Lewis',
            'work_key' => 'OL45804W',
            'isbn13s' => ['9780064404990'],
        ]);

        $result = $this->resolver()->resolve([
            'title' => 'The Lion, the Witch and the Wardrobe (Full-Color Edition)',
            'author' => 'C.S. Lewis',
            'isbn13s' => ['978-0-06-440499-0'],
        ]);

        $this->assertTrue($result['title']->is($row));
        $this->assertSame([], $result['ambiguous']);
        $this->assertSame(1, BookLibraryTitle::count());
        Http::assertNothingSent();
    }

    public function test_ol_short_circuit_when_step1_row_already_has_work_key(): void
    {
        Http::fake();
        $row = BookLibraryTitle::factory()->create([
            'title' => 'Charlotte\'s Web',
            'author' => 'E. B. White',
            'work_key' => 'OL483391W',
            'isbn13s' => ['9780064400558'],
        ]);

        // Extra unknown ISBN must not trigger an OL lookup either.
        $result = $this->resolver()->resolve([
            'title' => 'Charlotte\'s Web',
            'author' => 'E. B. White',
            'isbn13s' => ['9780064400558', '9780000000019'],
        ]);

        $this->assertTrue($result['title']->is($row));
        Http::assertNothingSent();
        $this->assertContains('9780000000019', $result['title']->fresh()->isbn13s);
    }

    public function test_step1_match_without_work_key_does_ol_lookup_and_stamps_work_key(): void
    {
        Http::fake([
            'openlibrary.org/isbn/9780064404990.json' => Http::response(
                $this->olEdition('OL45804W', ['9780060234812'], 77)
            ),
        ]);
        $row = BookLibraryTitle::factory()->create([
            'title' => 'The Lion, the Witch and the Wardrobe',
            'author' => 'C. S. Lewis',
            'work_key' => null,
            'isbn13s' => ['9780064404990'],
            'cover_url' => null,
        ]);

        $result = $this->resolver()->resolve([
            'title' => 'The Lion, the Witch and the Wardrobe',
            'author' => 'C. S. Lewis',
            'isbn13s' => ['0-06-440499-3'],
        ]);

        $this->assertTrue($result['title']->is($row));
        $fresh = $row->fresh();
        $this->assertSame('OL45804W', $fresh->work_key);
        $this->assertContains('9780064404990', $fresh->isbn13s);
        $this->assertContains('9780060234812', $fresh->isbn13s);
        $this->assertSame('https://covers.openlibrary.org/b/id/77-L.jpg', $fresh->cover_url);
        Http::assertSentCount(1);
    }

    // ── Step 2: Open Library work match ───────────────────────────────────

    public function test_ol_resolution_matches_existing_work_key_row_and_unions_isbns(): void
    {
        Http::fake([
            'openlibrary.org/isbn/9780064404990.json' => Http::response(
                $this->olEdition('OL45804W', ['9780064404990'])
            ),
        ]);
        // Existing row found earlier through a different edition: no ISBN overlap.
        $row = BookLibraryTitle::factory()->create([
            'title' => 'The Lion, the Witch and the Wardrobe',
            'author' => 'C. S. Lewis',
            'work_key' => 'OL45804W',
            'isbn13s' => ['9780060234812'],
        ]);

        $result = $this->resolver()->resolve([
            'title' => 'The Lion, the Witch & the Wardrobe',
            'author' => 'C. S. Lewis',
            'isbn13s' => ['978-0-06-440499-0'],
        ]);

        $this->assertTrue($result['title']->is($row));
        $fresh = $row->fresh();
        $this->assertEqualsCanonicalizing(
            ['9780060234812', '9780064404990'],
            $fresh->isbn13s
        );
        // Never overwrite non-null title/author.
        $this->assertSame('The Lion, the Witch and the Wardrobe', $fresh->title);
        $this->assertSame(1, BookLibraryTitle::count());
        Http::assertSentCount(1);
    }

    // ── Step 3: normalized exact match ────────────────────────────────────

    public function test_normalized_exact_match_tolerates_author_initial_spacing(): void
    {
        Http::fake();
        $row = BookLibraryTitle::factory()->create([
            'title' => 'Charlotte\'s Web',
            'author' => 'E. B. White',
        ]);

        $result = $this->resolver()->resolve([
            'title' => "Charlotte's Web",
            'author' => 'E.B. White',
        ]);

        $this->assertTrue($result['title']->is($row));
        $this->assertSame(1, BookLibraryTitle::count());
        Http::assertNothingSent();
    }

    public function test_title_match_with_conflicting_author_creates_new_row(): void
    {
        Http::fake();
        $row = BookLibraryTitle::factory()->create([
            'title' => 'Holes',
            'author' => 'Louis Sachar',
        ]);

        $result = $this->resolver()->resolve([
            'title' => 'Holes',
            'author' => 'Jane Doe',
        ]);

        $this->assertNotNull($result['title']);
        $this->assertFalse($result['title']->is($row));
        $this->assertSame(2, BookLibraryTitle::count());
    }

    public function test_stored_null_author_allows_match_and_fills_author(): void
    {
        Http::fake();
        $row = BookLibraryTitle::factory()->create([
            'title' => 'Hatchet',
            'author' => null,
        ]);

        $result = $this->resolver()->resolve([
            'title' => 'Hatchet',
            'author' => 'Gary Paulsen',
        ]);

        $this->assertTrue($result['title']->is($row));
        $this->assertSame('Gary Paulsen', $row->fresh()->author);
        $this->assertSame(1, BookLibraryTitle::count());
    }

    public function test_incoming_null_author_matches_stored_author_without_overwriting(): void
    {
        Http::fake();
        $row = BookLibraryTitle::factory()->create([
            'title' => 'Hatchet',
            'author' => 'Gary Paulsen',
        ]);

        $result = $this->resolver()->resolve(['title' => 'Hatchet']);

        $this->assertTrue($result['title']->is($row));
        $this->assertSame('Gary Paulsen', $row->fresh()->author);
    }

    public function test_reingesting_a_long_title_matches_its_stored_row_instead_of_duplicating(): void
    {
        Http::fake();

        // >255-char title and author, no ISBNs (the PluggedIn shape — title
        // matching is the ONLY dedup path). The model's saving hook clamps
        // stored title/author to 255, so the stored normalized_title derives
        // from the CLAMPED value; the resolver must clamp the incoming values
        // the same way or a re-ingest never matches its own row and every
        // pass creates a fresh duplicate.
        $item = [
            'title' => 'The Complete Annotated Chronicle of '.str_repeat('Adventures ', 30),
            'author' => 'Jane Marie '.str_repeat('Hyphenton-', 30).'Smith',
        ];
        $this->assertGreaterThan(255, mb_strlen($item['title']));
        $this->assertGreaterThan(255, mb_strlen($item['author']));

        $first = $this->resolver()->resolve($item);
        $second = $this->resolver()->resolve($item);

        $this->assertNotNull($first['title']);
        $this->assertSame([], $second['ambiguous']);
        $this->assertTrue($second['title']->is($first['title']));
        $this->assertSame(1, BookLibraryTitle::count());
        $this->assertLessThanOrEqual(255, mb_strlen($first['title']->fresh()->title));
        Http::assertNothingSent();
    }

    public function test_empty_normalized_title_never_matches_another_empty_normalized_row(): void
    {
        Http::fake();
        $row = BookLibraryTitle::factory()->create([
            'title' => '竜とそばかすの姫',
            'author' => null,
        ]);
        $this->assertSame('', $row->normalized_title);

        $result = $this->resolver()->resolve(['title' => '千と千尋の神隠し']);

        $this->assertNotNull($result['title']);
        $this->assertFalse($result['title']->is($row));
        $this->assertSame(2, BookLibraryTitle::count());
    }

    // ── Step 4: ambiguity + creation ──────────────────────────────────────

    public function test_two_candidates_is_ambiguous_no_match_no_create(): void
    {
        Http::fake();
        $a = BookLibraryTitle::factory()->create(['title' => 'Holes', 'author' => null]);
        $b = BookLibraryTitle::factory()->create(['title' => 'Holes', 'author' => null]);

        $result = $this->resolver()->resolve([
            'title' => 'Holes',
            'author' => 'Louis Sachar',
        ]);

        $this->assertNull($result['title']);
        $this->assertNotSame([], $result['ambiguous']);
        $this->assertSame('Holes', $result['ambiguous']['incoming']['title']);
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            array_column($result['ambiguous']['candidates'], 'id')
        );
        $this->assertSame(2, BookLibraryTitle::count());
    }

    public function test_zero_candidates_creates_row_with_normalized_isbns(): void
    {
        Http::fake(['openlibrary.org/isbn/*' => Http::response(null, 404)]);

        $result = $this->resolver()->resolve([
            'title' => 'Frindle',
            'author' => 'Andrew Clements',
            'year' => 1996,
            'isbn13s' => ['0-689-80669-8'],
        ]);

        $row = $result['title'];
        $this->assertNotNull($row);
        $this->assertSame([], $result['ambiguous']);
        $this->assertSame('Frindle', $row->title);
        $this->assertSame('Andrew Clements', $row->author);
        $this->assertSame(1996, $row->year);
        $this->assertNull($row->work_key);
        $this->assertSame(['9780689806698'], $row->isbn13s);
        $this->assertSame('frindle', $row->fresh()->normalized_title);
    }

    public function test_invalid_isbns_are_dropped_before_any_matching(): void
    {
        Http::fake();

        $result = $this->resolver()->resolve([
            'title' => 'Frindle',
            'author' => 'Andrew Clements',
            'isbn13s' => ['not-an-isbn', '12345'],
        ]);

        $this->assertSame([], $result['title']->isbn13s);
        Http::assertNothingSent();
    }

    // ── Merge rules ───────────────────────────────────────────────────────

    public function test_merge_fills_nulls_and_never_overwrites_non_null_fields(): void
    {
        Http::fake();
        $row = BookLibraryTitle::factory()->create([
            'title' => 'The Giver',
            'author' => 'Lois Lowry',
            'work_key' => 'OL98W',
            'year' => 1993,
            'cover_url' => 'https://covers.openlibrary.org/b/id/1-L.jpg',
            'description' => null,
            'isbn13s' => ['9780544336261'],
        ]);

        $result = $this->resolver()->resolve([
            'title' => 'The Giver (25th Anniversary Edition)',
            'author' => 'L. Lowry',
            'year' => 2018,
            'cover_url' => 'https://example.com/other.jpg',
            'description' => 'A boy discovers the dark secrets behind his community.',
            'isbn13s' => ['9780544336261', '978-0-547-99566-3'],
        ]);

        $this->assertTrue($result['title']->is($row));
        $fresh = $row->fresh();
        $this->assertSame('The Giver', $fresh->title);
        $this->assertSame('Lois Lowry', $fresh->author);
        $this->assertSame(1993, $fresh->year);
        $this->assertSame('https://covers.openlibrary.org/b/id/1-L.jpg', $fresh->cover_url);
        $this->assertSame('A boy discovers the dark secrets behind his community.', $fresh->description);
        $this->assertEqualsCanonicalizing(['9780544336261', '9780547995663'], $fresh->isbn13s);
    }

    public function test_work_key_collision_skips_stamp_and_logs_warning(): void
    {
        Log::spy();
        Http::fake([
            'openlibrary.org/isbn/9780064404990.json' => Http::response(
                $this->olEdition('OL45804W', ['9780064404990'])
            ),
        ]);
        // Row A: matched by ISBN in step 1, no work_key yet.
        $a = BookLibraryTitle::factory()->create([
            'title' => 'The Lion, the Witch and the Wardrobe',
            'author' => 'C. S. Lewis',
            'work_key' => null,
            'isbn13s' => ['9780064404990'],
        ]);
        // Row B: already carries the work_key OL resolves for A's ISBN.
        $b = BookLibraryTitle::factory()->create([
            'title' => 'The Lion the Witch and the Wardrobe (Collector\'s Edition)',
            'author' => 'C. S. Lewis',
            'work_key' => 'OL45804W',
            'isbn13s' => ['9780060234812'],
        ]);

        $result = $this->resolver()->resolve([
            'title' => 'The Lion, the Witch and the Wardrobe',
            'author' => 'C. S. Lewis',
            'isbn13s' => ['9780064404990', '978-0-547-99566-3'],
        ]);

        // Merge lands on A without violating the work_key unique constraint.
        $this->assertTrue($result['title']->is($a));
        $fresh = $a->fresh();
        $this->assertNull($fresh->work_key);
        $this->assertEqualsCanonicalizing(
            ['9780064404990', '9780547995663'],
            $fresh->isbn13s
        );
        $this->assertSame('OL45804W', $b->fresh()->work_key);
        $this->assertSame(2, BookLibraryTitle::count());

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'book-library: work_key collision, skipping stamp',
                \Mockery::on(function (array $context) use ($a, $b) {
                    return $context['work_key'] === 'OL45804W'
                        && $context['matched']['id'] === $a->id
                        && $context['matched']['title'] === $a->title
                        && $context['conflicting']['id'] === $b->id
                        && $context['conflicting']['title'] === $b->title;
                })
            );
    }

    // ── Narnia end-to-end dedup ───────────────────────────────────────────

    public function test_narnia_two_variants_resolve_to_single_row(): void
    {
        Http::fake([
            'openlibrary.org/isbn/9780064404990.json' => Http::response(
                $this->olEdition('OL45804W', ['9780064404990'], 8231856)
            ),
        ]);

        $first = $this->resolver()->resolve([
            'title' => 'The Lion, the Witch and the Wardrobe',
            'author' => 'C.S. Lewis',
            'isbn13s' => ['0-06-440499-3'],
        ]);

        $row = $first['title'];
        $this->assertNotNull($row);
        $this->assertSame('OL45804W', $row->work_key);
        $this->assertSame('https://covers.openlibrary.org/b/id/8231856-L.jpg', $row->cover_url);

        $second = $this->resolver()->resolve([
            'title' => 'The Lion, the Witch and the Wardrobe (50th Anniversary Edition)',
            'author' => 'C. S. Lewis',
            'isbn13s' => ['9780064404990'],
        ]);

        $this->assertTrue($second['title']->is($row));
        $this->assertSame(1, BookLibraryTitle::count());
        // Second pass short-circuits on the stored work_key: one OL call total.
        Http::assertSentCount(1);
    }
}
