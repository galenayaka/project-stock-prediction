<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Prediction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class PredictionService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.ml_service.url', 'http://localhost:8001'), '/');
    }

    public function predict(array $features, string $targetPeriod = '3m'): array
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-API-Key' => (string) config('services.ml_service.api_key', ''),
            ])
                ->timeout(60)
                ->retry(2, 500)
                ->post("{$this->baseUrl}/api/v1/predict", [
                    'features' => $features,
                    'target_period' => $targetPeriod,
                ]);
        } catch (ConnectionException $e) {
            Log::error('ML service unreachable', ['error' => $e->getMessage()]);

            throw new \RuntimeException(
                'AI prediction service is not running. Please start the ML service with: php artisan ml:start',
                0,
                $e,
            );
        }

        if ($response->failed()) {
            Log::error('ML service prediction request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException(
                "ML service returned status {$response->status()}: ".$response->body()
            );
        }

        /** @var array<string, mixed> */
        return $response->json();
    }

    public function isHealthy(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/health");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function predictAndStore(Prediction $prediction): Prediction
    {
        $prediction->markProcessing();

        $company = $prediction->company;
        $statement = $prediction->financialStatement;

        if (! $statement) {
            $prediction->markFailed('No financial statement associated with this prediction.');
            Log::warning('Prediction has no financial statement', ['prediction_id' => $prediction->id]);

            return $prediction;
        }

        $features = [
            'pe_ratio' => (float) $statement->pe_ratio,
            'debt_to_equity' => (float) $statement->debt_to_equity,
            'current_ratio' => (float) $statement->current_ratio,
            'free_cash_flow' => (float) $statement->free_cash_flow,
            'gross_margin' => (float) $statement->gross_margin,
            'operating_margin' => (float) $statement->operating_margin,
            'roe' => (float) $statement->roe,
            'roa' => (float) $statement->roa,
            'eps' => (float) $statement->eps,
            'market_cap' => (int) ($company->market_cap ?? 0),
            'revenue_growth' => $this->calculateRevenueGrowth($company),
            'filing_type' => $statement->filing_type,
        ];

        try {
            $result = $this->predict($features, $prediction->target_period ?? '3m');

            $prediction->markCompleted([
                'predicted_price' => $result['predicted_price'] ?? null,
                'confidence_score' => $result['confidence_score'] ?? null,
                'prediction_direction' => $result['direction'] ?? null,
                'feature_importance' => $result['feature_importance'] ?? null,
                'model_metadata' => [
                    'model' => $result['model'] ?? 'unknown',
                    'version' => $result['version'] ?? '0.0.0',
                    'requested_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            $prediction->markFailed($e->getMessage());
            Log::error('Prediction failed', [
                'prediction_id' => $prediction->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $prediction;
    }

    private function calculateRevenueGrowth(?Company $company): ?float
    {
        if (! $company) {
            return null;
        }

        $statements = $company->financialStatements()
            ->whereNotNull('revenue')
            ->orderBy('fiscal_year', 'desc')
            ->orderBy('fiscal_quarter', 'desc')
            ->take(2)
            ->get();

        if ($statements->count() < 2) {
            return null;
        }

        $current = (float) $statements[0]->revenue;
        $previous = (float) $statements[1]->revenue;

        if ($previous <= 0) {
            return null;
        }

        return round(($current - $previous) / $previous, 4);
    }
}
