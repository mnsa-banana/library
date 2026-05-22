<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryGroup extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
