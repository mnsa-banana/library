<?php

namespace App\Services\BookLibrary;

use App\Models\BookLibraryTitle;
use App\Models\BookListMembership;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Source-agnostic ingest: resolve an incoming item to a work row (WorkResolver
 * owns dedup + fill-null merge of author/cover/description/year + isbn union),
 * then apply what the resolver does not own — the min_age provenance write and
 * the list-membership upsert.
 */
final class IngestService
{
    /** Spec §Parent DB schema: precedence csm_index > wkar > nyt. */
    private const MIN_AGE_PRECEDENCE = ['csm_index' => 3, 'wkar' => 2, 'nyt' => 1];

    public function __construct(private WorkResolver $resolver) {}

    /**
     * @param  array  $item  keys: title (req), author?, year?, isbn13s?,
     *                       cover_url?, description?, min_age?, min_age_source?,
     *                       list_source (req), list_key (req), rank?,
     *                       weeks_on_list?, as_of_date?, review_url?, metadata?
     * @return ?BookLibraryTitle null when the resolver judged the item ambiguous
     */
    public function ingest(array $item, SyncRun $run): ?BookLibraryTitle
    {
        $listSource = $item['list_source'] ?? null;
        $listKey = $item['list_key'] ?? null;
        if (! is_string($listSource) || $listSource === '' || ! is_string($listKey) || $listKey === '') {
            throw new InvalidArgumentException('IngestService item requires list_source and list_key');
        }

        ['title' => $title, 'ambiguous' => $ambiguous] = $this->resolver->resolve($item);

        if ($title === null) {
            // List context rides along so `book:status --ambiguous` can dedupe
            // entries by incoming title + source.
            $run->logAmbiguous($ambiguous + ['list_source' => $listSource, 'list_key' => $listKey]);

            return null;
        }

        $this->applyMinAge($title, $item);

        $membership = BookListMembership::firstOrNew([
            'library_title_id' => $title->id,
            'list_source' => $listSource,
            'list_key' => $listKey,
        ]);

        // Newest data wins regardless of ingest order: the NYT history
        // backfill is forced to walk each list newest→oldest (the API only
        // exposes previous_published_date), so a title charting multiple
        // weeks is re-ingested with progressively OLDER stats — blindly
        // upserting would leave every multi-week title with its debut rank /
        // weeks_on_list / as_of_date. Skip the overwrite only when both sides
        // carry an as_of_date and the incoming one is strictly older;
        // null-dated sources (csm/pluggedin/wkar/award) and re-seeds always
        // update (re-seeds must refresh review_url/metadata).
        $incomingAsOf = $item['as_of_date'] ?? null;
        $staleIngest = $incomingAsOf !== null
            && $membership->as_of_date !== null
            && Carbon::parse($incomingAsOf)->lt($membership->as_of_date);

        if (! $staleIngest) {
            $membership->fill([
                'rank' => $item['rank'] ?? null,
                'weeks_on_list' => $item['weeks_on_list'] ?? null,
                'as_of_date' => $incomingAsOf,
                'review_url' => $item['review_url'] ?? null,
                'metadata' => $item['metadata'] ?? null,
            ])->save();
        }

        $run->bumpTitles();

        return $title;
    }

    /**
     * Provenance-ranked min_age write: update only when the incoming source's
     * rank is ≥ the stored source's rank, or nothing is stored yet — the
     * outcome is deterministic regardless of seed order.
     */
    private function applyMinAge(BookLibraryTitle $title, array $item): void
    {
        $minAge = $item['min_age'] ?? null;
        $source = $item['min_age_source'] ?? null;
        if ($minAge === null || $source === null) {
            return;
        }

        if ($title->min_age_source !== null) {
            $incomingRank = self::MIN_AGE_PRECEDENCE[$source] ?? 0;
            $storedRank = self::MIN_AGE_PRECEDENCE[$title->min_age_source] ?? 0;
            if ($incomingRank < $storedRank) {
                return;
            }
        }

        $title->min_age = $minAge;
        $title->min_age_source = $source;
        if ($title->isDirty()) {
            $title->save();
        }
    }
}
