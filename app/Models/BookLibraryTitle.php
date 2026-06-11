<?php

namespace App\Models;

use App\Services\BookLibrary\Normalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookLibraryTitle extends Model
{
    use HasFactory;

    protected $table = 'book_library_titles';

    protected $guarded = [];

    protected $casts = [
        'isbn13s' => 'array',
        'categories' => 'array',
        'preview_available' => 'boolean',
        'enriched_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Recompute on every save so later title/author edits stay in sync.
        static::saving(function (self $title) {
            $title->normalized_title = Normalizer::title($title->title);
            $title->normalized_author = Normalizer::author($title->author);
        });
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BookListMembership::class, 'library_title_id');
    }
}
