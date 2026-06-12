<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class NetflixAvailabilityController extends Controller
{
    /**
     * The full Netflix-US imdb_id set and the Kids subset — exactly the payload
     * streaming:push-availability used to send, inverted to a pull.
     */
    public function __invoke(): JsonResponse
    {
        $imdbIds = $this->netflixUsQuery()
            ->distinct()
            ->pluck('st.imdb_id')
            ->sort()
            ->values()
            ->all();

        $kidsImdbIds = $this->netflixUsQuery()
            ->where('st.netflix_kids_surfaced', true)
            ->distinct()
            ->pluck('st.imdb_id')
            ->sort()
            ->values()
            ->all();

        return response()->json([
            'imdb_ids' => $imdbIds,
            'kids_imdb_ids' => $kidsImdbIds,
        ]);
    }

    /**
     * Base query for distinct, imdb-identified, currently-tracked US Netflix
     * subscription titles. Shared by the full set and the Kids subset so the
     * two can't drift.
     *
     * Note: DB::table() bypasses the StreamingTitle model's SoftDeletes scope,
     * so we guard explicitly with whereNull to exclude soft-deleted rows.
     */
    private function netflixUsQuery(): Builder
    {
        return DB::table('streaming_title_offers as sto')
            ->join('streaming_titles as st', 'st.id', '=', 'sto.title_id')
            ->where('sto.service_id', 'netflix')
            ->where('sto.region', 'US')
            ->where('sto.type', 'subscription')
            ->whereNotNull('st.imdb_id')
            ->where('st.imdb_id', '!=', '')
            ->whereNull('st.deleted_at');
    }
}
