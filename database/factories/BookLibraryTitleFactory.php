<?php

namespace Database\Factories;

use App\Models\BookLibraryTitle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookLibraryTitle>
 */
class BookLibraryTitleFactory extends Factory
{
    protected $model = BookLibraryTitle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->sentence(3),
            'author' => fake()->name(),
            'year' => fake()->numberBetween(1950, 2026),
            'isbn13s' => [],
        ];
    }
}
