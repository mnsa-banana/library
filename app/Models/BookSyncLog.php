<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookSyncLog extends Model
{
    protected $table = 'book_sync_log';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];
}
