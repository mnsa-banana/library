<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookSyncLog extends Model
{
    protected $table = 'book_sync_log';

    public $timestamps = false;

    protected $guarded = [];

    protected $attributes = [
        'api_calls_used' => 0,
        'titles_processed' => 0,
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];
}
