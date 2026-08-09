<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use App\Models\UserWatchlist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserWatchlist>
 */
class UserWatchlistFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'target_price' => fake()->randomFloat(4, 10, 2000),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
