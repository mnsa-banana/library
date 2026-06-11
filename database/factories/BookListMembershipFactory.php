<?php

namespace Database\Factories;

use App\Models\BookLibraryTitle;
use App\Models\BookListMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookListMembership>
 */
class BookListMembershipFactory extends Factory
{
    protected $model = BookListMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'library_title_id' => BookLibraryTitle::factory(),
            'list_source' => fake()->randomElement(['csm_index', 'pluggedin_index', 'nyt', 'wkar', 'award']),
            'list_key' => fake()->unique()->slug(2),
            'rank' => fake()->numberBetween(1, 15),
            'weeks_on_list' => fake()->numberBetween(1, 50),
            'as_of_date' => fake()->date(),
        ];
    }
}
