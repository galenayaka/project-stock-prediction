<?php

namespace App\Http\Controllers;

use App\Http\Requests\TriggerPredictionRequest;
use App\Http\Resources\PredictionResource;
use App\Models\Company;
use App\Models\Prediction;
use App\Services\StockPredictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class PredictionController extends Controller
{
    /**
     * List predictions for a company.
     */
    public function index(Company $company): JsonResponse
    {
        $predictions = $company->predictions()
            ->with('financialStatement')
            ->latest()
            ->paginate(25);

        return response()->json([
            'data' => PredictionResource::collection($predictions),
            'meta' => [
                'current_page' => $predictions->currentPage(),
                'last_page' => $predictions->lastPage(),
                'total' => $predictions->total(),
            ],
        ]);
    }

    /**
     * Show a specific prediction.
     */
    public function show(Prediction $prediction): JsonResponse
    {
        $prediction->load(['company', 'financialStatement']);

        return response()->json([
            'data' => new PredictionResource($prediction),
        ]);
    }

    /**
     * Run Prediction — the main "Run Prediction" button endpoint.
     *
     * Accepts a timeframe (e.g. "3 Months", "6 Months", "1 Year") and
     * synchronously calls the StockPredictionService which:
     *   1. Queries all financial statement history for the company.
     *   2. Sends the enriched dataset to the Python AI microservice.
     *   3. The AI evaluates fundamental trends AND post-earnings price
     *      reactions via yfinance.
     *   4. Returns a signal (buy/hold/sell), predicted return %,
     *      confidence score, and key drivers.
     *
     * Returns the created Prediction as JSON so the UI can render
     * it immediately.
     */
    public function store(
        TriggerPredictionRequest $request,
        Company $company,
        StockPredictionService $predictionService,
    ): JsonResponse {
        try {
            $timeframe = $request->validated('timeframe', '3m');

            $prediction = $predictionService->predict($company, $timeframe);

            return response()->json([
                'data' => new PredictionResource($prediction->fresh()),
                'message' => 'Prediction completed successfully.',
            ], 201);
        } catch (\RuntimeException $e) {
            Log::warning('Prediction runtime error', [
                'ticker' => $company->ticker,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Prediction failed unexpectedly', [
                'ticker' => $company->ticker,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Prediction service error: '.$e->getMessage(),
            ], 500);
        }
    }
}
