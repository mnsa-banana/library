<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RestrictionController extends Controller
{
    private const SUPPORTED_PLATFORMS = ['netflix'];

    public function index(Request $request): JsonResponse
    {
        $platform = $request->input('platform');

        if (!$platform || !in_array($platform, self::SUPPORTED_PLATFORMS, true)) {
            return response()->json([
                'error' => 'Invalid or missing platform parameter. Supported: ' . implode(', ', self::SUPPORTED_PLATFORMS),
            ], 422);
        }

        $serviceId = $platform; // service IDs are slugs in the new schema
        $includeImplied = filter_var($request->input('include_implied', true), FILTER_VALIDATE_BOOLEAN);

        $subcategories = ['explicit_characters_or_relationships'];
        if ($includeImplied) {
            $subcategories[] = 'implied_or_coded';
        }

        $titles = DB::table('reports as r')
            ->whereNotNull('r.published_at')
            ->join('ratings as rat', function ($join) use ($subcategories) {
                $join->on('rat.report_id', '=', 'r.id')
                    ->where('rat.section_key', '=', 'themes_and_depictions')
                    ->where('rat.group_key', '=', 'relationships_and_family')
                    ->whereIn('rat.subcategory_key', $subcategories)
                    ->where('rat.present', '=', true);
            })
            ->join('streaming_titles as st', 'st.imdb_id', '=', 'r.imdb_id')
            ->join('streaming_title_offers as sto', function ($join) use ($serviceId) {
                $join->on('sto.title_id', '=', 'st.id')
                    ->where('sto.service_id', '=', $serviceId)
                    ->where('sto.region', '=', 'US')
                    ->where('sto.type', '=', 'subscription');
            })
            ->select([
                'r.id as report_id',
                'r.tmdb_id',
                'r.imdb_id',
                'r.title',
                'r.year',
                'r.content_type',
                'r.certification',
                'r.poster_url',
                DB::raw("MAX(CASE WHEN rat.subcategory_key = 'explicit_characters_or_relationships' AND rat.present = true THEN 1 ELSE 0 END) AS lgbtq_explicit"),
                DB::raw("MAX(CASE WHEN rat.subcategory_key = 'implied_or_coded' AND rat.present = true THEN 1 ELSE 0 END) AS lgbtq_implied"),
            ])
            ->groupBy('r.id', 'r.tmdb_id', 'r.imdb_id', 'r.title', 'r.year', 'r.content_type', 'r.certification', 'r.poster_url')
            ->orderBy('r.title')
            ->get();

        // Attach evidence from the first matching rating per report
        $reportIds = $titles->pluck('report_id')->all();
        $evidenceMap = [];
        if ($reportIds) {
            $evidenceRows = DB::table('ratings')
                ->where('section_key', 'themes_and_depictions')
                ->where('group_key', 'relationships_and_family')
                ->whereIn('subcategory_key', $subcategories)
                ->where('present', true)
                ->whereIn('report_id', $reportIds)
                ->select('report_id', 'evidence')
                ->get();

            foreach ($evidenceRows as $row) {
                if (!isset($evidenceMap[$row->report_id])) {
                    $evidenceMap[$row->report_id] = $row->evidence;
                }
            }
        }

        $titlesArray = $titles->map(function ($t) use ($evidenceMap) {
            return [
                'report_id' => $t->report_id,
                'tmdb_id' => $t->tmdb_id,
                'imdb_id' => $t->imdb_id,
                'title' => $t->title,
                'year' => $t->year,
                'content_type' => $t->content_type,
                'certification' => $t->certification,
                'poster_url' => $t->poster_url,
                'lgbtq_explicit' => (bool) $t->lgbtq_explicit,
                'lgbtq_implied' => (bool) $t->lgbtq_implied,
                'evidence' => $evidenceMap[$t->report_id] ?? null,
            ];
        })->all();

        // Counts for context
        $totalReports = DB::table('reports')->whereNotNull('published_at')->count();
        $totalWithLgbtq = DB::table('ratings')
            ->join('reports', 'reports.id', '=', 'ratings.report_id')
            ->whereNotNull('reports.published_at')
            ->where('ratings.section_key', 'themes_and_depictions')
            ->where('ratings.group_key', 'relationships_and_family')
            ->whereIn('ratings.subcategory_key', $subcategories)
            ->where('ratings.present', true)
            ->distinct('ratings.report_id')
            ->count('ratings.report_id');

        return response()->json([
            'platform' => $platform,
            'titles' => $titlesArray,
            'total_reports' => $totalReports,
            'total_with_lgbtq' => $totalWithLgbtq,
            'total_on_platform' => count($titlesArray),
        ]);
    }
}
