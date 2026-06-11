<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookListMembership extends Model
{
    use HasFactory;

    protected $table = 'book_list_memberships';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'as_of_date' => 'date',
    ];

    public function libraryTitle(): BelongsTo
    {
        return $this->belongsTo(BookLibraryTitle::class, 'library_title_id');
    }
}
