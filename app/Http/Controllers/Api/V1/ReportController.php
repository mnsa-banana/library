<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Services\StreamingAvailability\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 50);

        $query = Report::query()
            ->where('published', true)
            ->orderByDesc('published_at');

        if ($request->has('content_type')) {
            $query->where('content_type', $request->input('content_type'));
        }

        if ($request->filled('search')) {
            $query->where('title', 'ilike', '%' . $request->input('search') . '%');
        }

        $paginated = $query->paginate($perPage, [
            'id', 'content_type', 'title', 'year',
            'poster_url', 'certification', 'summary', 'published_at',
        ]);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $report = Report::with(['categoryGroups', 'ratings'])
            ->where('published', true)
            ->findOrFail($id);

        return response()->json([
            'id' => $report->id,
            'content_type' => $report->content_type,
            'title' => $report->title,
            'year' => $report->year,
            'poster_url' => $report->poster_url,
            'certification' => $report->certification,
            'rating' => $report->rating,
            'runtime' => $report->runtime,
            'overview' => $report->overview,
            'directors' => $report->directors,
            'creators' => $report->creators,
            'top_cast' => $report->top_cast,
            'summary' => $report->summary,
            'reception' => $report->reception,
            'heads_up' => $report->heads_up,
            'is_adaptation' => $report->is_adaptation,
            'source_material' => $report->source_material,
            'published_at' => $report->published_at,
            'category_groups' => $report->categoryGroups->map(fn ($cg) => [
                'section_key' => $cg->section_key,
                'group_key' => $cg->group_key,
                'notes' => $cg->notes,
            ]),
            'ratings' => $report->ratings->map(fn ($r) => [
                'section_key' => $r->section_key,
                'group_key' => $r->group_key,
                'subcategory_key' => $r->subcategory_key,
                'present' => $r->present,
                'level' => $r->level,
                'evidence' => $r->evidence,
            ]),
        ]);
    }

    public function streaming(int $id, CatalogService $catalog): JsonResponse
    {
        $report = Report::where('published', true)->find($id);

        if (!$report) {
            return response()->json([
                'subscription' => [],
                'free' => [],
                'rent' => [],
                'buy' => [],
            ]);
        }

        return response()->json($catalog->getStreamingOptions($report));
    }
}
