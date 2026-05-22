<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    public $timestamps = false;

    protected $casts = [
        'is_adaptation' => 'boolean',
        'published' => 'boolean',
        'published_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $guarded = [];

    public function categoryGroups(): HasMany
    {
        return $this->hasMany(CategoryGroup::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }
}
