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

    public function show(Prediction $prediction): JsonResponse
    {
        $prediction->load(['company', 'financialStatement']);

        return response()->json([
            'data' => new PredictionResource($prediction),
        ]);
    }

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
