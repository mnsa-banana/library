<?php

namespace Tests\Unit\Models;

use App\Models\BookLibraryTitle;
use App\Models\BookListMembership;
use App\Models\BookSyncLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookLibraryTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_book_library_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('book_library_titles'));
        $this->assertTrue(Schema::hasTable('book_list_memberships'));
        $this->assertTrue(Schema::hasTable('book_sync_log'));
    }

    public function test_title_casts_round_trip(): void
    {
        $title = BookLibraryTitle::factory()->create([
            'isbn13s' => ['9780064404990', '9780060234812'],
            'categories' => ['Juvenile Fiction'],
            'preview_available' => true,
            'enriched_at' => '2026-06-10 12:00:00',
        ]);

        $fresh = $title->fresh();
        $this->assertSame(['9780064404990', '9780060234812'], $fresh->isbn13s);
        $this->assertSame(['Juvenile Fiction'], $fresh->categories);
        $this->assertTrue($fresh->preview_available);
        $this->assertInstanceOf(Carbon::class, $fresh->enriched_at);
        $this->assertSame('2026-06-10 12:00:00', $fresh->enriched_at->toDateTimeString());
    }

    public function test_title_isbn13s_defaults_to_empty_array(): void
    {
        $title = BookLibraryTitle::factory()->create();

        $this->assertSame([], $title->fresh()->isbn13s);
    }

    public function test_membership_casts_round_trip(): void
    {
        $membership = BookListMembership::factory()->create([
            'metadata' => ['grade_band' => '3-5', 'winner' => true],
            'as_of_date' => '2026-06-07',
            'weeks_on_list' => 12,
        ]);

        $fresh = $membership->fresh();
        $this->assertSame(['grade_band' => '3-5', 'winner' => true], $fresh->metadata);
        $this->assertInstanceOf(Carbon::class, $fresh->as_of_date);
        $this->assertSame('2026-06-07', $fresh->as_of_date->toDateString());
        $this->assertSame(12, $fresh->weeks_on_list);
    }

    public function test_relations_round_trip(): void
    {
        $title = BookLibraryTitle::factory()->create();
        $membership = BookListMembership::factory()->create([
            'library_title_id' => $title->id,
        ]);

        $this->assertTrue($title->memberships()->first()->is($membership));
        $this->assertTrue($membership->libraryTitle->is($title));
    }

    public function test_duplicate_membership_raises_unique_violation(): void
    {
        $title = BookLibraryTitle::factory()->create();
        BookListMembership::factory()->create([
            'library_title_id' => $title->id,
            'list_source' => 'nyt',
            'list_key' => 'picture-books',
        ]);

        $this->expectException(QueryException::class);
        BookListMembership::factory()->create([
            'library_title_id' => $title->id,
            'list_source' => 'nyt',
            'list_key' => 'picture-books',
        ]);
    }

    public function test_saving_computes_normalized_fields(): void
    {
        $title = BookLibraryTitle::factory()->create([
            'title' => 'The Lion, the Witch and the Wardrobe',
            'author' => 'C. S. Lewis',
        ]);

        $this->assertSame('lion the witch and the wardrobe', $title->normalized_title);
        $this->assertSame('c s lewis', $title->normalized_author);
    }

    public function test_updating_title_or_author_recomputes_normalized_fields(): void
    {
        $title = BookLibraryTitle::factory()->create([
            'title' => 'The Lion, the Witch and the Wardrobe',
            'author' => 'C. S. Lewis',
        ]);

        $title->update([
            'title' => 'A Wrinkle in Time',
            'author' => "Madeleine L'Engle",
        ]);

        $fresh = $title->fresh();
        $this->assertSame('wrinkle in time', $fresh->normalized_title);
        $this->assertSame('madeleine lengle', $fresh->normalized_author);
    }

    public function test_null_author_normalizes_to_null(): void
    {
        $title = BookLibraryTitle::factory()->create(['author' => null]);

        $this->assertNull($title->fresh()->normalized_author);
    }

    public function test_sync_log_persists_with_casts(): void
    {
        $log = BookSyncLog::create([
            'sync_type' => 'weekly',
            'status' => 'running',
            'metadata' => ['ambiguous' => []],
        ]);

        $fresh = $log->fresh();
        $this->assertSame('weekly', $fresh->sync_type);
        $this->assertSame(['ambiguous' => []], $fresh->metadata);
        $this->assertInstanceOf(Carbon::class, $fresh->started_at);
    }
}
