<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function title(): BelongsTo
    {
        return $this->belongsTo(StreamingTitle::class, 'title_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(StreamingService::class, 'service_id');
    }
}
