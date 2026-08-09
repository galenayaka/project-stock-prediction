<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\DailyPriceHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyPriceHistory>
 */
class DailyPriceHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $open = $this->faker->randomFloat(2, 50, 500);
        $close = $this->faker->randomFloat(2, $open * 0.9, $open * 1.1);

        return [
            'company_id' => Company::factory(),
            'date' => $this->faker->date(),
            'open' => $open,
            'high' => $this->faker->randomFloat(2, $open, $open * 1.05),
            'low' => $this->faker->randomFloat(2, $open * 0.95, $open),
            'close' => $close,
            'volume' => $this->faker->numberBetween(100_000, 100_000_000),
            'price_change_pct' => round((($close - $open) / $open) * 100, 6),
        ];
    }
}
