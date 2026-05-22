<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StreamingService extends Model
{
    protected $table = 'streaming_services';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    public function offers(): HasMany
    {
        return $this->hasMany(StreamingTitleOffer::class, 'service_id');
    }
}
