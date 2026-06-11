<?php

namespace Tests\Unit\Services\BookLibrary;

use App\Services\BookLibrary\GoogleBooksClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleBooksClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** One volumes response whose volumeInfo carries the given publishedDate (or none). */
    private function fakeVolume(?string $publishedDate): void
    {
        $volumeInfo = ['title' => 'Smile'];
        if ($publishedDate !== null) {
            $volumeInfo['publishedDate'] = $publishedDate;
        }

        Http::fake([
            'www.googleapis.com/books/v1/volumes*' => Http::response([
                'totalItems' => 1,
                'items' => [['id' => 'gb-1', 'volumeInfo' => $volumeInfo]],
            ]),
        ]);
    }

    private function lookupYear(): ?int
    {
        $client = new GoogleBooksClient(delayMs: 0, backoffBaseMs: 0);

        return $client->lookup(['9780545132060'], 'Smile', null)['year'];
    }

    public function test_year_extracted_from_year_only_published_date(): void
    {
        $this->fakeVolume('2004');
        $this->assertSame(2004, $this->lookupYear());
    }

    public function test_year_extracted_from_year_month_published_date(): void
    {
        $this->fakeVolume('2004-05');
        $this->assertSame(2004, $this->lookupYear());
    }

    public function test_year_extracted_from_full_published_date(): void
    {
        $this->fakeVolume('2004-05-01');
        $this->assertSame(2004, $this->lookupYear());
    }

    public function test_year_null_when_published_date_missing(): void
    {
        $this->fakeVolume(null);
        $this->assertNull($this->lookupYear());
    }

    public function test_year_null_when_published_date_carries_no_leading_year(): void
    {
        $this->fakeVolume('n.d.');
        $this->assertNull($this->lookupYear());
    }
}
