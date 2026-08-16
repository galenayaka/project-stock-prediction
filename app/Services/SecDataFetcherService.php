<?php

namespace App\Services;

use App\Models\Company;
use App\Models\DailyPriceHistory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for fetching OHLC price history data.
 *
 * Uses the Python ML microservice (which wraps yfinance) to pull
 * daily/weekly OHLCV candles and stores them in the
 * daily_price_histories table.
 */
final class SecDataFetcherService
{
    private string $baseUrl;

    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.ml_service.url', 'http://localhost:8001'), '/');
        $this->apiKey = (string) config('services.ml_service.api_key', '');
    }

    /**
     * Fetch OHLC history for a company from the ML service and store it.
     *
     * @param  string  $timeframe  One of: '1W', '1M', '3M', '6M', '1Y', '5Y'
     * @param  string  $interval  One of: '1d', '1wk', '1mo'
     * @return Collection<int, DailyPriceHistory>
     *
     * @throws ConnectionException|\RuntimeException
     */
    public function fetchOhlcHistory(
        Company $company,
        string $timeframe = '1W',
        string $interval = '1d',
    ): Collection {
        $period = $this->normalizeTimeframe($timeframe);

        Log::info('SecDataFetcherService: fetching OHLC history', [
            'company_id' => $company->id,
            'ticker' => $company->ticker,
            'timeframe' => $timeframe,
            'period' => $period,
            'interval' => $interval,
        ]);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->apiKey,
            ])
                ->timeout(30)
                ->retry(2, 1000)
                ->post("{$this->baseUrl}/api/v1/data/historical", [
                    'ticker' => $company->ticker,
                    'period' => $period,
                    'interval' => $interval,
                ]);

            if ($response->failed()) {
                Log::error('SecDataFetcherService: OHLC fetch failed', [
                    'ticker' => $company->ticker,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw new \RuntimeException(
                    "Failed to fetch OHLC data for {$company->ticker}: HTTP {$response->status()}"
                );
            }

            /** @var list<array<string, mixed>> $candles */
            $candles = $response->json('data', []);

            if (! is_array($candles) || empty($candles)) {
                Log::warning('SecDataFetcherService: no candles returned', [
                    'ticker' => $company->ticker,
                ]);

                return collect();
            }
        } catch (ConnectionException $e) {
            Log::error('SecDataFetcherService: connection failed', [
                'ticker' => $company->ticker,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $imported = collect();

        foreach ($candles as $candle) {
            $date = $candle['date'] ?? null;
            $open = $candle['open'] ?? null;
            $high = $candle['high'] ?? null;
            $low = $candle['low'] ?? null;
            $close = $candle['close'] ?? null;
            $volume = $candle['volume'] ?? null;

            if (! $date || $close === null) {
                continue;
            }

            $priceChangePct = null;
            if ($open !== null && (float) $open > 0) {
                $priceChangePct = round(((float) $close - (float) $open) / (float) $open * 100, 6);
            }

            $record = DailyPriceHistory::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'date' => $date,
                ],
                [
                    'open' => $open !== null ? (float) $open : null,
                    'high' => $high !== null ? (float) $high : null,
                    'low' => $low !== null ? (float) $low : null,
                    'close' => $close !== null ? (float) $close : null,
                    'volume' => $volume !== null ? (int) $volume : null,
                    'price_change_pct' => $priceChangePct,
                ]
            );

            $imported->push($record);
        }

        // Update the company's latest price from the most recent close.
        $latest = $imported->last();
        if ($latest instanceof DailyPriceHistory && $latest->close !== null) {
            $company->update([
                'latest_price' => $latest->close,
                'latest_price_date' => $latest->date,
            ]);
        }

        Log::info('SecDataFetcherService: OHLC history imported', [
            'company_id' => $company->id,
            'ticker' => $company->ticker,
            'records' => $imported->count(),
        ]);

        return $imported;
    }

    /**
     * Check whether the data service is reachable.
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
     * Normalize user-friendly timeframes to yfinance period strings.
     */
    private function normalizeTimeframe(string $timeframe): string
    {
        return match (strtoupper($timeframe)) {
            '1W' => '7d',
            '2W' => '14d',
            '1M' => '1mo',
            '3M' => '3mo',
            '6M' => '6mo',
            '1Y' => '1y',
            '2Y' => '2y',
            '5Y' => '5y',
            default => $timeframe,
        };
    }
}
