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
 * Stock Prediction Service (Enhanced)
 *
 * Communicates with the Python FastAPI AI microservice to generate
 * trading signals based on:
 *   1. Historical financial statement trends (EPS, ROE, margins, leverage)
 *   2. Post-earnings price reactions (fetched by the Python service via yfinance)
 *
 * Called by PredictionController when the user clicks "Run Prediction"
 * on the company dashboard with a selected timeframe.
 */
final class StockPredictionService
{
    private string $baseUrl;

    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.ai.url', 'http://localhost:8001'), '/');
        $this->apiKey = (string) config('services.ai.api_key', '');
    }

    /**
     * Run an enhanced prediction for a company.
     *
     * Builds a JSON payload containing every financial statement on file
     * plus the latest known price, sends it to the Python AI microservice,
     * and stores the returned signal in the predictions table.
     *
     * @param  Company  $company   The company to predict for
     * @param  string   $timeframe One of: "1m", "3m", "6m", "1y"
     * @return Prediction           The newly created Prediction model
     *
     * @throws \RuntimeException|ConnectionException
     */
    public function predict(Company $company, string $timeframe = '3m'): Prediction
    {
        // 1. Build financial history payload
        $financialHistory = $this->buildFinancialHistory($company);

        if (empty($financialHistory)) {
            throw new \RuntimeException(
                "No financial statements available for {$company->ticker}. Import financial data first."
            );
        }

        // 2. Build request payload
        $payload = [
            'ticker' => $company->ticker,
            'timeframe' => $this->normalizeTimeframe($timeframe),
            'current_price' => $company->latest_price,
            'financial_history' => $financialHistory,
        ];

        // 3. Create prediction record (status: processing)
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

        // 4. Send to Python AI microservice
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

            // 5. Compute technical momentum from local DB
            $techMomentum = $this->calculateTechnicalMomentum($company, $timeframe);

            // 6. Get fundamental signal and drivers from Python
            $fundamentalSignal = $result['signal_type'] ?? 'hold';
            $keyDrivers = $result['key_drivers'] ?? [];
            $confidenceScore = (float) ($result['confidence_score'] ?? 0.5);
            $confidenceBreakdown = $result['confidence_breakdown'] ?? [];

            // 7. Apply technical alignment adjustment
            $alignmentResult = $this->applyTechnicalAlignment(
                fundamentalSignal: $fundamentalSignal,
                techMomentum: $techMomentum,
                keyDrivers: $keyDrivers,
                confidenceScore: $confidenceScore,
                confidenceBreakdown: $confidenceBreakdown,
            );

            // 8. Map final AI response to Prediction columns
            $prediction->markCompleted([
                'predicted_price' => $result['target_price'] ?? null,
                'confidence_score' => $alignmentResult['adjusted_confidence'],
                'prediction_direction' => $this->mapSignalToDirection($result['signal_type'] ?? 'hold'),
                'feature_importance' => $this->mapKeyDrivers($alignmentResult['drivers']),
                'model_metadata' => [
                    'model' => $result['model'] ?? 'xgboost_rf_ensemble',
                    'version' => $result['version'] ?? '1.1.0',
                    'signal_type' => $result['signal_type'] ?? null,
                    'predicted_return' => $result['predicted_return'] ?? null,
                    'current_price' => $result['current_price'] ?? null,
                    'confidence_breakdown' => $alignmentResult['confidence_breakdown'],
                    'requested_at' => now()->toIso8601String(),
                ],
            ]);

            // Also update the explicit columns
            $prediction->update([
                'signal_type' => $result['signal_type'] ?? null,
                'predicted_return' => $result['predicted_return'] ?? null,
            ]);

            Log::info('StockPredictionService: prediction completed', [
                'prediction_id' => $prediction->id,
                'signal_type' => $result['signal_type'] ?? 'unknown',
                'confidence' => $result['confidence_score'] ?? 0,
            ]);

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

    // ── Private helpers ────────────────────────────────────────────

    /**
     * Build an array of financial-statement records for the AI payload.
     *
     * Returns statements ordered oldest → newest so the Python service
     * can compute trends across time.
     *
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

    // ── Technical Momentum (Local DB) ─────────────────────────────

    /**
     * Calculate technical price momentum from the local
     * daily_price_histories table.
     *
     * Queries the most recent OHLC candles for the company, computes
     * net price change and green/red candle ratio, and returns a
     * structured technical signal.
     *
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

        // Default empty result
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

        // Net price change over the period
        $netChangePct = null;
        if ($startOpen !== null && $startOpen > 0 && $latestClose !== null) {
            $netChangePct = round((($latestClose - $startOpen) / $startOpen) * 100, 4);
        }

        // Green candle ratio (Close > Open)
        $greenCount = $candles->filter(function (DailyPriceHistory $c): bool {
            return $c->close !== null && $c->open !== null && (float) $c->close > (float) $c->open;
        })->count();
        $greenCandleRatio = round($greenCount / $dataPoints, 4);

        // Determine technical signal
        $technicalSignal = 'neutral';
        if ($netChangePct !== null) {
            if ($netChangePct > 1.5) {
                $technicalSignal = 'bullish';
            } elseif ($netChangePct < -1.5) {
                $technicalSignal = 'bearish';
            }
            // Reinforce with candle ratio: majority green/red can override borderline net change
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

        // Build human-readable detail
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
     * Apply technical alignment adjustment to the confidence score
     * and driver list.
     *
     * Compares the technical momentum signal with the fundamental
     * signal from the Python AI service, then adjusts confidence
     * and appends a technical driver accordingly.
     *
     * Rules:
     *   - ALIGNED:   technical signal == fundamental signal → +10% confidence
     *   - CONTRADICT: technical signal opposite fundamental   → −10% penalty
     *   - NEUTRAL:   either signal is neutral                 → no adjustment
     *
     * Confidence is always clamped between 30% (floor) and 95% (cap).
     *
     * @param  string               $fundamentalSignal   'buy', 'hold', or 'sell'
     * @param  array<string, mixed> $techMomentum        Result from calculateTechnicalMomentum()
     * @param  list<array<string, mixed>> $keyDrivers     Fundamental drivers from Python
     * @param  float                $confidenceScore     Current confidence (0-1)
     * @param  array<string, mixed> $confidenceBreakdown Existing breakdown from Python
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

        // Only adjust if both signals are directional (not neutral)
        if ($fundamentalDirection !== 'neutral' && $techSignal !== 'neutral' && $dataPoints >= 3) {
            if ($fundamentalDirection === $techSignal) {
                // ALIGNMENT — confidence bonus
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
                // CONTRADICTION — confidence penalty
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
