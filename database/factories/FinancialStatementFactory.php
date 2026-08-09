<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\FinancialStatement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialStatement>
 */
class FinancialStatementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $revenue = fake()->randomFloat(2, 100_000_000, 500_000_000_000);
        $netIncome = $revenue * fake()->randomFloat(4, 0.02, 0.25);
        $totalAssets = $revenue * fake()->randomFloat(4, 0.5, 3);
        $totalLiabilities = $totalAssets * fake()->randomFloat(4, 0.2, 0.9);

        return [
            'company_id' => Company::factory(),
            'fiscal_year' => fake()->numberBetween(2019, 2026),
            'fiscal_quarter' => fake()->randomElement([0, 1, 2, 3, 4]),
            'filing_type' => fake()->randomElement(['10-K', '10-Q']),
            'revenue' => $revenue,
            'net_income' => $netIncome,
            'eps' => fake()->randomFloat(4, -2, 50),
            'pe_ratio' => fake()->randomFloat(4, 5, 200),
            'debt_to_equity' => fake()->randomFloat(4, 0.1, 5),
            'current_ratio' => fake()->randomFloat(4, 0.3, 5),
            'free_cash_flow' => fake()->randomFloat(2, -1_000_000_000, 100_000_000_000),
            'gross_margin' => fake()->randomFloat(4, 0.1, 0.8),
            'operating_margin' => fake()->randomFloat(4, -0.2, 0.5),
            'roe' => fake()->randomFloat(4, -0.5, 1.5),
            'roa' => fake()->randomFloat(4, -0.2, 0.4),
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'shares_outstanding' => fake()->numberBetween(10_000_000, 20_000_000_000),
            'reported_date' => fake()->date(),
            'filing_date' => fake()->date(),
        ];
    }
}
