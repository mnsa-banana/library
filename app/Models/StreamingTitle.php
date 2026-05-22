<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StreamingTitle extends Model
{
    use SoftDeletes;

    protected $table = 'streaming_titles';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'genres' => 'array',
        'cast_members' => 'array',
        'directors' => 'array',
        'creators' => 'array',
        'umbrella_services' => 'array',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function offers(): HasMany
    {
        return $this->hasMany(StreamingTitleOffer::class, 'title_id');
    }
}
