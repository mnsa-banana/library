<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class StreamingTitleOffer extends Model
{
    protected $table = 'streaming_title_offers';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'price_amount' => 'decimal:2',
        'expires_on' => 'datetime',
        'available_from' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Build a Carbon from a UNIX timestamp, clamping far-future "never expires"
     * sentinels to a value that survives the datetime-cast round-trip.
     *
     * The streaming-availability API encodes "never expires" as a huge timestamp.
     * Some land in year 10000+, which PHP/Carbon can *format* but cannot *parse*
     * back ("Double time specification"), so the value detonates the moment the
     * Eloquent `datetime` cast re-hydrates it. Clamp to the max parseable
     * datetime, matching the 4-digit `9999-…` sentinels already in the table.
     */
    public static function safeDatetime(?int $timestamp): ?Carbon
    {
        if ($timestamp === null) {
            return null;
        }

        $date = Carbon::createFromTimestamp($timestamp);

        return $date->year > 9999
            ? Carbon::create(9999, 12, 31, 23, 59, 59)
            : $date;
    }

    public function title(): BelongsTo
    {
        return $this->belongsTo(StreamingTitle::class, 'title_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(StreamingService::class, 'service_id');
    }
}
