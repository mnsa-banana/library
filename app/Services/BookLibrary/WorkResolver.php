<?php

namespace App\Services\BookLibrary;

use App\Models\BookLibraryTitle;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Work-level dedup for incoming seed items (spec §Dedup resolver).
 *
 * Resolution order:
 *  1. ISBN match against stored isbn13s (normalized).
 *  2. Open Library ISBN→work → match work_key; stamp work_key + union ISBNs
 *     onto a row found by other means. Short-circuited when step 1 already
 *     matched a row carrying work_key (resume must not redo OL lookups).
 *  3. Normalized exact title match + author last-name agreement (a conflicting
 *     author is NOT a match; stored-null author allows the match and fills it).
 *  4. Multiple candidates → ambiguous (logged + skipped). Zero → create.
 *
 * min_age provenance is NOT handled here — it lives in IngestService.
 */
class WorkResolver
{
    public function __construct(private OpenLibraryClient $openLibrary) {}

    /**
     * @param  array  $item  keys: title (required), author?, year?,
     *                       isbn13s?: string[] (raw, normalized inside),
     *                       cover_url?, description?
     * @return array{title: ?BookLibraryTitle, ambiguous: array}
     */
    public function resolve(array $item): array
    {
        $incomingTitle = trim((string) ($item['title'] ?? ''));
        if ($incomingTitle === '') {
            throw new InvalidArgumentException('WorkResolver item requires a title');
        }

        $isbns = $this->normalizedIsbns($item['isbn13s'] ?? []);

        // Step 1: ISBN match against stored isbn13s.
        $matched = $this->matchByIsbn($isbns, $ambiguous, $item);
        if ($ambiguous !== []) {
            return ['title' => null, 'ambiguous' => $ambiguous];
        }

        // Step 2: Open Library ISBN→work. Short-circuit when step 1 already
        // matched a row carrying work_key.
        $ol = null;
        if ($isbns !== [] && ! ($matched && $matched->work_key !== null)) {
            $ol = $this->lookupOpenLibrary($isbns);
        }
        if ($matched === null && $ol !== null) {
            $matched = BookLibraryTitle::where('work_key', $ol['work_key'])->first();
        }

        // Step 3: normalized exact title + author last-name agreement.
        if ($matched === null) {
            $matched = $this->matchByNormalizedTitle($incomingTitle, $item, $ambiguous);
            if ($ambiguous !== []) {
                return ['title' => null, 'ambiguous' => $ambiguous];
            }
        }

        if ($matched !== null) {
            return ['title' => $this->merge($matched, $item, $isbns, $ol), 'ambiguous' => []];
        }

        return ['title' => $this->create($incomingTitle, $item, $isbns, $ol), 'ambiguous' => []];
    }

    /** @return array<string> raw ISBNs normalized to digits-only ISBN-13, invalid dropped */
    private function normalizedIsbns(array $raw): array
    {
        $normalized = array_filter(array_map(
            fn ($isbn) => is_string($isbn) ? Normalizer::isbn13($isbn) : null,
            $raw
        ));

        return array_values(array_unique($normalized));
    }

    private function matchByIsbn(array $isbns, ?array &$ambiguous, array $item): ?BookLibraryTitle
    {
        $ambiguous = [];
        if ($isbns === []) {
            return null;
        }

        $candidates = BookLibraryTitle::where(function ($query) use ($isbns) {
            foreach ($isbns as $isbn) {
                $query->orWhereJsonContains('isbn13s', $isbn);
            }
        })->get();

        if ($candidates->count() > 1) {
            $ambiguous = $this->ambiguousPayload('isbn', $item, $candidates->all());

            return null;
        }

        return $candidates->first();
    }

    /** @return array{work_key: string, isbn13s: array<string>, cover_url: ?string}|null */
    private function lookupOpenLibrary(array $isbns): ?array
    {
        foreach ($isbns as $isbn) {
            $resolved = $this->openLibrary->resolveIsbn($isbn);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function matchByNormalizedTitle(string $title, array $item, ?array &$ambiguous): ?BookLibraryTitle
    {
        $ambiguous = [];

        $normalizedTitle = Normalizer::title($title);
        // Guard: non-Latin titles normalize to '' — never treat that as
        // equality, or unrelated titles would silently merge.
        if ($normalizedTitle === '') {
            return null;
        }

        $incomingLastName = Normalizer::authorLastName($item['author'] ?? null);

        $candidates = BookLibraryTitle::where('normalized_title', $normalizedTitle)
            ->get()
            ->filter(function (BookLibraryTitle $row) use ($incomingLastName) {
                $storedLastName = Normalizer::authorLastName($row->author);

                // Conflict only when both sides carry an author and the last
                // names disagree; a null on either side allows the match.
                return $incomingLastName === null
                    || $storedLastName === null
                    || $storedLastName === $incomingLastName;
            })
            ->values();

        if ($candidates->count() > 1) {
            $ambiguous = $this->ambiguousPayload('normalized_title', $item, $candidates->all());

            return null;
        }

        return $candidates->first();
    }

    /**
     * Merge an incoming item onto a matched row: fill nulls for
     * author/cover_url/description/year, union isbn13s, stamp work_key —
     * never overwrite non-null title/author.
     */
    private function merge(BookLibraryTitle $row, array $item, array $isbns, ?array $ol): BookLibraryTitle
    {
        $row->author ??= $item['author'] ?? null;
        $row->year ??= $item['year'] ?? null;
        $row->cover_url ??= $item['cover_url'] ?? $ol['cover_url'] ?? null;
        $row->description ??= $item['description'] ?? null;

        $workKey = $ol['work_key'] ?? null;
        if ($row->work_key === null && $workKey !== null && $this->workKeyIsFree($workKey, $row->id)) {
            $row->work_key = $workKey;
        }

        $union = array_values(array_unique(array_merge(
            $row->isbn13s ?? [],
            $isbns,
            $ol['isbn13s'] ?? []
        )));
        if ($union !== ($row->isbn13s ?? [])) {
            $row->isbn13s = $union;
        }

        if ($row->isDirty()) {
            $row->save();
        }

        return $row;
    }

    private function create(string $title, array $item, array $isbns, ?array $ol): BookLibraryTitle
    {
        $workKey = $ol['work_key'] ?? null;

        return BookLibraryTitle::create([
            'title' => $title,
            'author' => $item['author'] ?? null,
            'year' => $item['year'] ?? null,
            'work_key' => $workKey !== null && $this->workKeyIsFree($workKey) ? $workKey : null,
            'cover_url' => $item['cover_url'] ?? $ol['cover_url'] ?? null,
            'description' => $item['description'] ?? null,
            'isbn13s' => array_values(array_unique(array_merge($isbns, $ol['isbn13s'] ?? []))),
        ]);
    }

    /** work_key is unique; never stamp one that another row already carries. */
    private function workKeyIsFree(string $workKey, ?int $exceptId = null): bool
    {
        return BookLibraryTitle::where('work_key', $workKey)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->doesntExist();
    }

    private function ambiguousPayload(string $step, array $item, array $candidates): array
    {
        $payload = [
            'step' => $step,
            'incoming' => [
                'title' => $item['title'],
                'author' => $item['author'] ?? null,
                'isbn13s' => $this->normalizedIsbns($item['isbn13s'] ?? []),
            ],
            'candidates' => array_map(fn (BookLibraryTitle $row) => [
                'id' => $row->id,
                'title' => $row->title,
                'author' => $row->author,
            ], $candidates),
        ];

        Log::warning('book-library: ambiguous work match, skipping', $payload);

        return $payload;
    }
}
