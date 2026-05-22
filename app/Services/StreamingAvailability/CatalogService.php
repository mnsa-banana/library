<?php

namespace App\Services\StreamingAvailability;

use App\Models\Report;
use App\Models\StreamingTitle;
use Illuminate\Support\Facades\DB;

class CatalogService
{
    private const QUALITY_RANK = ['uhd' => 3, 'hd' => 2, 'sd' => 1];

    private const EMPTY_RESULT = [
        'subscription' => [],
        'free' => [],
        'rent' => [],
        'buy' => [],
    ];

    /** API type → response group key. `addon` collapses into `subscription`. */
    private const TYPE_MAP = [
        'subscription' => 'subscription',
        'free' => 'free',
        'rent' => 'rent',
        'buy' => 'buy',
        'addon' => 'subscription',
    ];

    public function getStreamingOptions(Report $report): array
    {
        $title = $this->findTitle($report);
        if (!$title) {
            return self::EMPTY_RESULT;
        }

        $offers = DB::table('streaming_title_offers as o')
            ->join('streaming_services as s', 's.id', '=', 'o.service_id')
            ->where('o.title_id', $title->id)
            ->where('o.region', 'US')
            ->select('s.id as service_id', 's.name', 'o.type', 'o.link', 'o.video_quality',
                     'o.price_amount', 'o.price_currency')
            ->get();

        if ($offers->isEmpty()) {
            return self::EMPTY_RESULT;
        }

        return $this->groupAndDedup($offers);
    }

    private function findTitle(Report $report): ?StreamingTitle
    {
        if ($report->imdb_id) {
            $t = StreamingTitle::where('imdb_id', $report->imdb_id)->first();
            if ($t) return $t;
        }

        if ($report->tmdb_id) {
            $tmdbType = $report->content_type === 'movie' ? 'movie' : 'tv';
            return StreamingTitle::where('tmdb_id', $report->tmdb_id)
                ->where('tmdb_type', $tmdbType)
                ->first();
        }

        return null;
    }

    private function groupAndDedup($offers): array
    {
        $result = self::EMPTY_RESULT;
        $seen = [];

        foreach ($offers as $o) {
            $group = self::TYPE_MAP[$o->type] ?? null;
            if (!$group) continue;

            $key = $o->service_id . '|' . $group;
            $rank = self::QUALITY_RANK[$o->video_quality] ?? 0;

            if (isset($seen[$key]) && $seen[$key]['rank'] >= $rank) {
                continue;
            }

            $entry = [
                'name' => $o->name,
                'type' => $o->type,
                'web_url' => $o->link,
                'quality' => $o->video_quality,
            ];
            if (in_array($group, ['rent', 'buy'])) {
                $entry['price'] = $o->price_amount !== null ? (float) $o->price_amount : null;
                $entry['currency'] = $o->price_currency;
            }

            $seen[$key] = ['rank' => $rank, 'group' => $group, 'entry' => $entry];
        }

        foreach ($seen as $item) {
            $result[$item['group']][] = $item['entry'];
        }

        usort($result['rent'], fn($a, $b) => ($a['price'] ?? 0) <=> ($b['price'] ?? 0));
        usort($result['buy'], fn($a, $b) => ($a['price'] ?? 0) <=> ($b['price'] ?? 0));

        return $result;
    }
}
