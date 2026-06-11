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
            // Column limit: title/author are varchar(255) — scraper values are
            // unbounded and Postgres rejects anything longer (sqlite tests
            // don't enforce it). Clamp BEFORE normalizing; the normalizer
            // never lengthens, so the normalized fields stay ≤255 too.
            $title->title = mb_substr($title->title, 0, 255);
            if ($title->author !== null) {
                $title->author = mb_substr($title->author, 0, 255);
            }

            $title->normalized_title = Normalizer::title($title->title);
            $title->normalized_author = Normalizer::author($title->author);
        });
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BookListMembership::class, 'library_title_id');
    }
}
