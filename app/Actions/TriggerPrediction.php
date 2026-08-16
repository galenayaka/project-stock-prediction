<?php

namespace App\Actions;

use App\Jobs\RunPrediction;
use App\Models\Company;
use App\Models\Prediction;
use App\Services\PredictionService;
use Illuminate\Support\Facades\Log;

final class TriggerPrediction
{
    public function __construct(
        private readonly PredictionService $predictionService,
    ) {}

    public function handle(Company $company, string $targetPeriod = '3m'): Prediction
    {
        $statement = $company->latestFinancialStatement();

        if (! $statement) {
            throw new \RuntimeException("No financial statement available for company {$company->ticker}");
        }

        $prediction = Prediction::create([
            'company_id' => $company->id,
            'financial_statement_id' => $statement->id,
            'target_period' => $targetPeriod,
            'status' => 'pending',
        ]);

        Log::info('Prediction triggered', [
            'prediction_id' => $prediction->id,
            'company_id' => $company->id,
            'ticker' => $company->ticker,
            'target_period' => $targetPeriod,
        ]);

        // Dispatch to queue for async processing
        RunPrediction::dispatch($prediction);

        return $prediction;
    }
}
