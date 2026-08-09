<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\FinancialStatement;
use App\Models\Prediction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prediction>
 */
class PredictionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'financial_statement_id' => FinancialStatement::factory(),
            'predicted_price' => fake()->randomFloat(4, 5, 3000),
            'confidence_score' => fake()->randomFloat(4, 0.3, 0.95),
            'prediction_direction' => fake()->randomElement(['bullish', 'bearish', 'neutral']),
            'target_period' => fake()->randomElement(['1m', '3m', '6m', '1y']),
            'feature_importance' => [
                'pe_ratio' => fake()->randomFloat(4, 0, 1),
                'debt_to_equity' => fake()->randomFloat(4, 0, 1),
                'free_cash_flow' => fake()->randomFloat(4, 0, 1),
                'roe' => fake()->randomFloat(4, 0, 1),
                'eps' => fake()->randomFloat(4, 0, 1),
            ],
            'model_metadata' => [
                'model' => 'xgboost',
                'version' => '1.0.0',
                'hyperparams' => [
                    'n_estimators' => 100,
                    'max_depth' => 6,
                ],
            ],
            'status' => fake()->randomElement(['pending', 'processing', 'completed', 'failed']),
        ];
    }

    /**
     * Indicate that the prediction is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    /**
     * Indicate that the prediction is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Indicate that the prediction failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => fake()->sentence(),
        ]);
    }
}
