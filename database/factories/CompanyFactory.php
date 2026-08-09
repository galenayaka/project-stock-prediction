<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticker' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->company(),
            'sector' => fake()->randomElement(['Technology', 'Healthcare', 'Financials', 'Energy', 'Consumer Cyclical', 'Industrials']),
            'industry' => fake()->word(),
            'market_cap' => fake()->numberBetween(100_000_000, 3_000_000_000_000),
            'description' => fake()->paragraph(),
            'cik' => (string) fake()->unique()->numberBetween(100000, 9999999),
            'latest_price' => fake()->randomFloat(4, 5, 3000),
            'latest_price_date' => now()->subDays(fake()->numberBetween(1, 5)),
        ];
    }
}
