<?php

namespace App\Services;

use App\Models\Company;
use App\Models\DailyPriceHistory;
use App\Models\FinancialStatement;
use App\Models\Prediction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generates trading signals by sending a company's financial history to the
 * Python ML microservice and blending the result with local price momentum.
 */
final class StockPredictionService
{
    private string $baseUrl;

    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.ml_service.url', 'http://localhost:8001'), '/');
        $this->apiKey = (string) config('services.ml_service.api_key', '');
    }

    /**
     * @throws \RuntimeException|ConnectionException
     */
    public function predict(Company $company, string $timeframe = '3m'): Prediction
    {
        $financialHistory = $this->buildFinancialHistory($company);

        if (empty($financialHistory)) {
            throw new \RuntimeException(
                "No financial statements available for {$company->ticker}. Import financial data first."
            );
        }

        $payload = [
            'ticker' => $company->ticker,
            'timeframe' => $this->normalizeTimeframe($timeframe),
            'current_price' => $company->latest_price,
            'financial_history' => $financialHistory,
        ];

        $prediction = Prediction::create([
            'company_id' => $company->id,
            'target_period' => $this->normalizeTimeframe($timeframe),
            'status' => 'processing',
        ]);

        Log::info('StockPredictionService: sending enhanced prediction request', [
            'prediction_id' => $prediction->id,
            'ticker' => $company->ticker,
            'timeframe' => $timeframe,
            'history_count' => count($financialHistory),
        ]);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->apiKey,
            ])
                ->timeout(90)
                ->retry(2, 1000)
                ->post("{$this->baseUrl}/api/v1/predict/enhanced", $payload);

            if ($response->failed()) {
                $errorBody = $response->body();
                Log::error('AI service prediction request failed', [
                    'status' => $response->status(),
                    'body' => $errorBody,
                ]);

                $prediction->markFailed(
                    "AI service returned status {$response->status()}: {$errorBody}"
                );

                return $prediction;
            }

            /** @var array<string, mixed> $result */
            $result = $response->json();

            $techMomentum = $this->calculateTechnicalMomentum($company, $timeframe);

            $fundamentalSignal = $result['signal_type'] ?? 'hold';
            $keyDrivers = $result['key_drivers'] ?? [];
            $confidenceScore = (float) ($result['confidence_score'] ?? 0.5);
            $confidenceBreakdown = $result['confidence_breakdown'] ?? [];

            $alignmentResult = $this->applyTechnicalAlignment(
                fundamentalSignal: $fundamentalSignal,
                techMomentum: $techMomentum,
                keyDrivers: $keyDrivers,
                confidenceScore: $confidenceScore,
                confidenceBreakdown: $confidenceBreakdown,
            );

            $prediction->markCompleted([
                'predicted_price' => $result['target_price'] ?? null,
                'confidence_score' => $alignmentResult['adjusted_confidence'],
                'prediction_direction' => $this->mapSignalToDirection($result['signal_type'] ?? 'hold'),
                'signal_type' => $result['signal_type'] ?? null,
                'predicted_return' => $result['predicted_return'] ?? null,
                'feature_importance' => $this->mapKeyDrivers($alignmentResult['drivers']),
                'model_metadata' => [
                    'model' => $result['model'] ?? 'xgboost_rf_ensemble',
                    'version' => $result['version'] ?? '1.1.0',
                    'current_price' => $result['current_price'] ?? null,
                    'confidence_breakdown' => $alignmentResult['confidence_breakdown'],
                    'requested_at' => now()->toIso8601String(),
                ],
            ]);

            Log::info('StockPredictionService: prediction completed', [
                'prediction_id' => $prediction->id,
                'signal_type' => $result['signal_type'] ?? 'unknown',
                'confidence' => $result['confidence_score'] ?? 0,
            ]);

        } catch (ConnectionException $e) {
            $message = 'AI prediction service is not running. Please start the ML service with: php artisan ml:start';
            $prediction->markFailed($message);
            Log::error('StockPredictionService: AI service unreachable', [
                'prediction_id' => $prediction->id,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException($message, 0, $e);
        } catch (\Throwable $e) {
            $prediction->markFailed($e->getMessage());
            Log::error('StockPredictionService: prediction failed', [
                'prediction_id' => $prediction->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $prediction->fresh();
    }

    /**
     * Check whether the AI microservice is reachable.
     */
    public function isHealthy(): bool
    {
        try {
            return Http::timeout(5)
                ->get("{$this->baseUrl}/health")
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildFinancialHistory(Company $company): array
    {
        return $company->financialStatements()
            ->orderBy('fiscal_year')
            ->orderBy('fiscal_quarter')
            ->get()
            ->map(fn (FinancialStatement $s): array => [
                'fiscal_year' => $s->fiscal_year,
                'fiscal_quarter' => $s->fiscal_quarter,
                'filing_type' => $s->filing_type,
                'revenue' => $s->revenue !== null ? (float) $s->revenue : null,
                'net_income' => $s->net_income !== null ? (float) $s->net_income : null,
                'eps' => $s->eps !== null ? (float) $s->eps : null,
                'pe_ratio' => $s->pe_ratio !== null ? (float) $s->pe_ratio : null,
                'debt_to_equity' => $s->debt_to_equity !== null ? (float) $s->debt_to_equity : null,
                'current_ratio' => $s->current_ratio !== null ? (float) $s->current_ratio : null,
                'free_cash_flow' => $s->free_cash_flow !== null ? (float) $s->free_cash_flow : null,
                'gross_margin' => $s->gross_margin !== null ? (float) $s->gross_margin : null,
                'operating_margin' => $s->operating_margin !== null ? (float) $s->operating_margin : null,
                'roe' => $s->roe !== null ? (float) $s->roe : null,
                'roa' => $s->roa !== null ? (float) $s->roa : null,
                'reported_date' => $s->reported_date?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Normalize user-friendly timeframe labels to the format the
     * Python service expects ("1m", "3m", "6m", "1y").
     */
    private function normalizeTimeframe(string $timeframe): string
    {
        return match (mb_strtolower(trim($timeframe))) {
            '1 month', '1 months', '1m' => '1m',
            '3 months', '3 month', '3m' => '3m',
            '6 months', '6 month', '6m' => '6m',
            '1 year', '1y' => '1y',
            default => '3m',
        };
    }

    /**
     * Map the AI signal_type to a prediction_direction string.
     */
    private function mapSignalToDirection(string $signalType): string
    {
        return match ($signalType) {
            'buy' => 'bullish',
            'sell' => 'bearish',
            default => 'neutral',
        };
    }

    /**
     * Convert KeyDriver objects from the AI response into a storable
     * feature_importance array.
     *
     * @param  list<array{factor:string, impact:string, detail?:string}>  $keyDrivers
     * @return list<array{feature:string, importance:float, impact:string}>
     */
    private function mapKeyDrivers(array $keyDrivers): array
    {
        $total = count($keyDrivers) ?: 1;

        return array_map(function (array $driver, int $i) use ($total): array {
            return [
                'feature' => $driver['factor'] ?? "driver_{$i}",
                'importance' => round(1.0 / $total, 4),
                'impact' => $driver['impact'] ?? 'neutral',
            ];
        }, $keyDrivers, array_keys($keyDrivers));
    }

    /**
     * @return array{net_change_pct: float|null, green_candle_ratio: float|null, technical_signal: string, data_points: int, start_open: float|null, latest_close: float|null, detail: string}
     */
    public function calculateTechnicalMomentum(Company $company, string $timeframe): array
    {
        $tradingDays = $this->timeframeToTradingDays($timeframe);

        $candles = $company->dailyPriceHistories()
            ->orderBy('date', 'desc')
            ->limit($tradingDays)
            ->get()
            ->sortBy('date')
            ->values();

        $dataPoints = $candles->count();

        $empty = [
            'net_change_pct' => null,
            'green_candle_ratio' => null,
            'technical_signal' => 'neutral',
            'data_points' => $dataPoints,
            'start_open' => null,
            'latest_close' => null,
            'detail' => 'Insufficient price data — cannot compute technical momentum.',
        ];

        if ($dataPoints < 3) {
            return $empty;
        }

        $first = $candles->first();
        $last = $candles->last();

        $startOpen = $first->open !== null ? (float) $first->open : null;
        $latestClose = $last->close !== null ? (float) $last->close : null;

        $netChangePct = null;
        if ($startOpen !== null && $startOpen > 0 && $latestClose !== null) {
            $netChangePct = round((($latestClose - $startOpen) / $startOpen) * 100, 4);
        }

        $greenCount = $candles->filter(function (DailyPriceHistory $c): bool {
            return $c->close !== null && $c->open !== null && (float) $c->close > (float) $c->open;
        })->count();
        $greenCandleRatio = round($greenCount / $dataPoints, 4);

        $technicalSignal = 'neutral';
        if ($netChangePct !== null) {
            if ($netChangePct > 1.5) {
                $technicalSignal = 'bullish';
            } elseif ($netChangePct < -1.5) {
                $technicalSignal = 'bearish';
            }
            if ($greenCandleRatio >= 0.7 && $technicalSignal === 'neutral') {
                $technicalSignal = 'bullish';
            } elseif ($greenCandleRatio <= 0.3 && $technicalSignal === 'neutral') {
                $technicalSignal = 'bearish';
            }
        } elseif ($greenCandleRatio >= 0.7) {
            $technicalSignal = 'bullish';
        } elseif ($greenCandleRatio <= 0.3) {
            $technicalSignal = 'bearish';
        }

        $detailParts = [];
        if ($netChangePct !== null) {
            $detailParts[] = sprintf('Net change: %+.2f%% over %d candles', $netChangePct, $dataPoints);
        }
        $detailParts[] = sprintf('Green candles: %d/%d (%.0f%%)', $greenCount, $dataPoints, $greenCandleRatio * 100);

        return [
            'net_change_pct' => $netChangePct,
            'green_candle_ratio' => $greenCandleRatio,
            'technical_signal' => $technicalSignal,
            'data_points' => $dataPoints,
            'start_open' => $startOpen,
            'latest_close' => $latestClose,
            'detail' => implode(' | ', $detailParts),
        ];
    }

    /**
     * Aligned signals gain confidence; contradictory signals lose it.
     * Confidence is clamped between 30% and 95%.
     *
     * @return array{adjusted_confidence: float, drivers: list<array<string, mixed>>, confidence_breakdown: array<string, mixed>}
     */
    private function applyTechnicalAlignment(
        string $fundamentalSignal,
        array $techMomentum,
        array $keyDrivers,
        float $confidenceScore,
        array $confidenceBreakdown,
    ): array {
        $techSignal = $techMomentum['technical_signal'] ?? 'neutral';
        $netChangePct = $techMomentum['net_change_pct'] ?? null;
        $greenRatio = $techMomentum['green_candle_ratio'] ?? null;
        $dataPoints = $techMomentum['data_points'] ?? 0;
        $detail = $techMomentum['detail'] ?? '';

        // Map fundamental signal to bullish/bearish/neutral
        $fundamentalDirection = match ($fundamentalSignal) {
            'buy' => 'bullish',
            'sell' => 'bearish',
            default => 'neutral',
        };

        $adjustment = 0.0;
        $techDriver = null;
        $alignmentResult = 'none';

        // Only adjust when both signals are directional.
        if ($fundamentalDirection !== 'neutral' && $techSignal !== 'neutral' && $dataPoints >= 3) {
            if ($fundamentalDirection === $techSignal) {
                $adjustment = +0.10;
                $alignmentResult = 'aligned';
                $techDriver = [
                    'factor' => 'Technical Price Action Alignment',
                    'impact' => $fundamentalDirection === 'bullish' ? 'positive' : 'negative',
                    'detail' => sprintf(
                        'Daily/Weekly Open-to-Close price momentum confirms the %s fundamental trend. %s',
                        $fundamentalDirection,
                        $detail
                    ),
                ];
            } else {
                $adjustment = -0.10;
                $alignmentResult = 'contradiction';
                $techDriver = [
                    'factor' => 'Technical Price Action Divergence',
                    'impact' => $fundamentalDirection === 'bullish' ? 'negative' : 'positive',
                    'detail' => sprintf(
                        'Short-term Open-to-Close price trend is moving counter to %s fundamental health. %s',
                        $fundamentalDirection,
                        $detail
                    ),
                ];
            }
        } elseif ($dataPoints < 3) {
            $alignmentResult = 'insufficient_data';
        }

        // Compute adjusted confidence (clamped between 30% and 95%)
        $rawConfidence = $confidenceScore + $adjustment;
        $adjustedConfidence = round(max(0.30, min(0.95, $rawConfidence)), 4);

        // Build enhanced confidence breakdown
        $laravelTechData = [
            'alignment_result' => $alignmentResult,
            'adjustment' => round($adjustment, 4),
            'raw_confidence' => round($rawConfidence, 4),
            'fundamental_direction' => $fundamentalDirection,
            'technical_signal' => $techSignal,
            'net_change_pct' => $netChangePct,
            'green_candle_ratio' => $greenRatio,
            'data_points' => $dataPoints,
            'detail' => $detail,
            'driver_added' => $techDriver !== null,
            'driver_factor' => $techDriver['factor'] ?? null,
            'driver_impact' => $techDriver['impact'] ?? null,
            'driver_detail' => $techDriver['detail'] ?? null,
        ];

        // Merge Laravel technical data into the Python confidence breakdown
        $enhancedBreakdown = array_merge($confidenceBreakdown ?: [], [
            'technical_alignment' => $laravelTechData,
        ]);

        // Append tech driver if present
        $drivers = $keyDrivers;
        if ($techDriver !== null) {
            $drivers[] = $techDriver;
        }

        Log::info('StockPredictionService: technical alignment applied', [
            'fundamental_signal' => $fundamentalSignal,
            'fundamental_direction' => $fundamentalDirection,
            'technical_signal' => $techSignal,
            'alignment' => $alignmentResult,
            'adjustment' => sprintf('%+.0f%%', $adjustment * 100),
            'original_confidence' => round($confidenceScore * 100, 1).'%',
            'adjusted_confidence' => round($adjustedConfidence * 100, 1).'%',
            'net_change_pct' => $netChangePct,
            'data_points' => $dataPoints,
        ]);

        return [
            'adjusted_confidence' => $adjustedConfidence,
            'drivers' => $drivers,
            'confidence_breakdown' => $enhancedBreakdown,
        ];
    }

    /**
     * Convert a prediction timeframe to an approximate number of
     * trading days for querying daily_price_histories.
     */
    private function timeframeToTradingDays(string $timeframe): int
    {
        return match ($this->normalizeTimeframe($timeframe)) {
            '1m' => 21,
            '3m' => 63,
            '6m' => 126,
            '1y' => 252,
            default => 63,
        };
    }
}
