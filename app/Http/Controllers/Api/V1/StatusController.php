<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BookSyncLog;
use App\Models\StreamingSyncLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class StatusController extends Controller
{
    /**
     * Last successful sync per pipeline. Doubles as the staleness / dead-cron
     * check: consumers (or a human with the admin token) alarm on old values.
     *
     * streaming → streaming:update pipeline runs (only complete when every step
     * succeeded); services → streaming:refresh-services; books → any book sync.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'streaming' => $this->lastCompletedAt(StreamingSyncLog::query()->where('sync_type', 'pipeline')),
            'services' => $this->lastCompletedAt(StreamingSyncLog::query()->where('sync_type', 'service_refresh')),
            'books' => $this->lastCompletedAt(BookSyncLog::query()),
        ]);
    }

    private function lastCompletedAt(Builder $query): ?string
    {
        $completedAt = $query->where('status', 'completed')->max('completed_at');

        return $completedAt === null
            ? null
            : CarbonImmutable::parse($completedAt)->toIso8601String();
    }
}
