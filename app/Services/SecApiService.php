<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FinancialStatement;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for fetching financial data from the SEC EDGAR API.
 * Uses the SEC's XBRL API for standardized financial statement data.
 */
final class SecApiService
{
    private const SEC_API_BASE = 'https://data.sec.gov/api/xbrl';

    private const SEC_SUBMISSIONS_BASE = 'https://data.sec.gov/submissions';

    /**
     * SEC EDGAR rate limit: 10 requests per second.
     * We enforce a 150ms gap between calls from this process.
     */
    private const MIN_REQUEST_INTERVAL_SECONDS = 0.15;

    /**
     * Timestamp (float microtime) of the last SEC API call.
     */
    private static float $lastRequestTime = 0.0;

    private string $userAgent;

    private int $timeout;

    private int $connectTimeout;

    public function __construct()
    {
        $this->userAgent = (string) config('services.sec_edgar.user_agent', 'StockPredictionApp/1.0');
        $this->timeout = (int) config('services.sec_edgar.timeout', 120);
        $this->connectTimeout = (int) config('services.sec_edgar.connect_timeout', 15);
    }

    /**
     * Enforce the SEC's 10-requests-per-second rate limit by sleeping
     * if the last call was made less than MIN_REQUEST_INTERVAL ago.
     */
    private function respectRateLimit(): void
    {
        $now = microtime(true);
        $elapsed = $now - self::$lastRequestTime;

        if (self::$lastRequestTime > 0 && $elapsed < self::MIN_REQUEST_INTERVAL_SECONDS) {
            $sleepFor = (int) ((self::MIN_REQUEST_INTERVAL_SECONDS - $elapsed) * 1_000_000);
            usleep($sleepFor);
        }

        self::$lastRequestTime = microtime(true);
    }

    /**
     * Build an HTTP client preconfigured with User-Agent, timeouts and
     * exponential backoff for rate-limited responses.
     */
    private function httpClient(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/json',
        ])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry(3, function (int $attempt): int {
                // Exponential backoff: 2s, 4s, 8s
                return 2000 * (2 ** ($attempt - 1));
            }, function (\Throwable $e): bool {
                // Retry on connection errors, timeouts, and server errors
                if ($e instanceof ConnectionException) {
                    Log::warning('SEC API: connection error, retrying...', [
                        'error' => $e->getMessage(),
                    ]);

                    return true;
                }

                return false;
            });
    }

    /**
     * Fetch company facts (XBRL-tagged financial data) from SEC.
     *
     * @return array<string, mixed>
     *
     * @throws ConnectionException|\RuntimeException
     */
    public function fetchCompanyFacts(string $cik): array
    {
        $cikPadded = str_pad($cik, 10, '0', STR_PAD_LEFT);
        $url = self::SEC_API_BASE."/companyfacts/CIK{$cikPadded}.json";

        $this->respectRateLimit();

        try {
            $response = $this->httpClient()->get($url);
        } catch (ConnectionException $e) {
            Log::error('SEC API connection failed after retries', [
                'cik' => $cik,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                "SEC EDGAR is unreachable for CIK {$cik}. "
                .'This may be due to rate limiting or network issues. '
                .'Please wait 30 seconds and try again.'
            );
        }

        if ($response->status() === 429) {
            Log::warning('SEC API rate limited', ['cik' => $cik]);

            throw new \RuntimeException(
                "SEC EDGAR rate limit exceeded for CIK {$cik}. "
                .'The SEC allows 10 requests per second. Please wait and retry.'
            );
        }

        if ($response->status() === 403) {
            Log::warning('SEC API access denied', [
                'cik' => $cik,
                'user_agent' => $this->userAgent,
            ]);

            throw new \RuntimeException(
                "SEC EDGAR denied access for CIK {$cik}. "
                .'Verify your User-Agent header includes a valid email address '
                .'per SEC Fair Access policy.'
            );
        }

        if ($response->failed()) {
            Log::warning('SEC API request failed', [
                'cik' => $cik,
                'status' => $response->status(),
                'body_preview' => substr($response->body(), 0, 500),
            ]);

            throw new \RuntimeException(
                "SEC API returned HTTP {$response->status()} for CIK {$cik}."
            );
        }

        /** @var array<string, mixed> */
        return $response->json();
    }

    /**
     * Fetch the latest submissions metadata for a company.
     *
     * @return array<string, mixed>
     *
     * @throws ConnectionException|\RuntimeException
     */
    public function fetchSubmissions(string $cik): array
    {
        $cikPadded = str_pad($cik, 10, '0', STR_PAD_LEFT);
        $url = self::SEC_SUBMISSIONS_BASE."/CIK{$cikPadded}.json";

        $this->respectRateLimit();

        try {
            $response = $this->httpClient()->get($url);
        } catch (ConnectionException $e) {
            Log::error('SEC submissions API connection failed', [
                'cik' => $cik,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                "SEC EDGAR submissions unreachable for CIK {$cik}. Please retry."
            );
        }

        if ($response->failed()) {
            Log::warning('SEC submissions API request failed', [
                'cik' => $cik,
                'status' => $response->status(),
            ]);

            throw new \RuntimeException(
                "SEC Submissions API returned HTTP {$response->status()} for CIK {$cik}."
            );
        }

        /** @var array<string, mixed> */
        return $response->json();
    }

    /**
     * Extract standardized financial metrics from SEC facts data.
     *
     * @param  array<string, mixed>  $facts
     * @return Collection<int, array<string, mixed>>
     */
    public function extractFinancialMetrics(array $facts): Collection
    {
        /** @var array<string, array<string, mixed>> $metrics */
        $metrics = [];

        $usGaap = $facts['facts']['us-gaap'] ?? [];

        $metricMappings = [
            // Revenue
            'RevenueFromContractWithCustomerExcludingAssessedTax' => 'revenue',
            'Revenues' => 'revenue',
            'RevenueFromContractWithCustomerIncludingAssessedTax' => 'revenue',
            'SalesRevenueNet' => 'revenue',
            // Net Income
            'NetIncomeLoss' => 'net_income',
            'ProfitLoss' => 'net_income',
            // EPS
            'EarningsPerShareBasic' => 'eps',
            'EarningsPerShareDiluted' => 'eps',
            // Debt-to-Equity
            'DebtToEquityRatio' => 'debt_to_equity',
            // Free Cash Flow
            'FreeCashFlow' => 'free_cash_flow',
            // Gross Profit (computed into gross_margin ratio)
            'GrossProfit' => 'gross_margin',
            // Operating Income (computed into operating_margin ratio)
            'OperatingIncomeLoss' => 'operating_margin',
            // Assets / Liabilities (for ROE, ROA computation)
            'Assets' => 'total_assets',
            'Liabilities' => 'total_liabilities',
            // Shares Outstanding
            'CommonStockSharesOutstanding' => 'shares_outstanding',
            'WeightedAverageNumberOfSharesOutstandingBasic' => 'shares_outstanding',
        ];

        foreach ($metricMappings as $xbrlTag => $dbColumn) {
            if (! isset($usGaap[$xbrlTag]['units'])) {
                continue;
            }

            foreach ($usGaap[$xbrlTag]['units'] as $entries) {
                foreach ($entries as $entry) {
                    $form = $entry['form'] ?? null;

                    if (! in_array($form, ['10-K', '10-Q'], true)) {
                        continue;
                    }

                    $fy = (int) ($entry['fy'] ?? 0);
                    $fp = $entry['fp'] ?? 'FY';
                    $fiscalQuarter = $fp === 'FY' ? 0 : (int) str_replace('Q', '', $fp);

                    $key = "{$fy}_{$fiscalQuarter}_{$form}";

                    if (! isset($metrics[$key])) {
                        $metrics[$key] = [
                            'fiscal_year' => $fy,
                            'fiscal_quarter' => $fiscalQuarter,
                            'filing_type' => $form,
                            'reported_date' => $entry['end'] ?? $entry['filed'] ?? now()->toDateString(),
                            'filing_date' => $entry['filed'] ?? null,
                        ];
                    }

                    $metrics[$key][$dbColumn] = $entry['val'] ?? null;
                }
            }
        }

        return collect(array_values($metrics))
            ->map(fn (array $m) => $this->computeDerivedRatios($m));
    }

    /**
     * Convert raw SEC dollar values into the ratios expected by the
     * gross_margin, operating_margin, roe and roa columns.
     *
     * @param  array<string, mixed>  $metric
     * @return array<string, mixed>
     */
    private function computeDerivedRatios(array $metric): array
    {
        $revenue = (float) ($metric['revenue'] ?? 0);

        // gross_margin = GrossProfit / Revenue
        if (! empty($metric['gross_margin']) && $revenue > 0) {
            $metric['gross_margin'] = round((float) $metric['gross_margin'] / $revenue, 6);
        } else {
            $metric['gross_margin'] = null;
        }

        // operating_margin = OperatingIncome / Revenue
        if (! empty($metric['operating_margin']) && $revenue > 0) {
            $metric['operating_margin'] = round((float) $metric['operating_margin'] / $revenue, 6);
        } else {
            $metric['operating_margin'] = null;
        }

        // roe = NetIncome / (TotalAssets - TotalLiabilities)
        $netIncome = (float) ($metric['net_income'] ?? 0);
        $totalAssets = (float) ($metric['total_assets'] ?? 0);
        $totalLiabilities = (float) ($metric['total_liabilities'] ?? 0);
        $equity = $totalAssets - $totalLiabilities;

        if ($equity > 0 && $netIncome !== 0.0) {
            $metric['roe'] = round($netIncome / $equity, 6);
        }

        // roa = NetIncome / TotalAssets
        if ($totalAssets > 0 && $netIncome !== 0.0) {
            $metric['roa'] = round($netIncome / $totalAssets, 6);
        }

        return $metric;
    }

    /**
     * Import financial data for a company from SEC into the database.
     *
     * @return Collection<int, FinancialStatement>
     *
     * @throws ConnectionException|\RuntimeException
     */
    public function importForCompany(Company $company): Collection
    {
        if (! $company->cik) {
            throw new \InvalidArgumentException("Company {$company->ticker} has no CIK registered.");
        }

        Log::info('SecApiService: fetching SEC facts', [
            'ticker' => $company->ticker,
            'cik' => $company->cik,
        ]);

        $facts = $this->fetchCompanyFacts($company->cik);

        // Validate response structure
        if (! isset($facts['facts'])) {
            Log::warning('SEC facts response missing "facts" key', [
                'ticker' => $company->ticker,
                'cik' => $company->cik,
                'available_keys' => array_keys($facts),
            ]);

            throw new \RuntimeException(
                "Unexpected SEC response format for {$company->ticker}. "
                .'The company may not have XBRL-tagged financial data available.'
            );
        }

        // Try US GAAP first, fall back to IFRS for international filers
        $taxonomy = 'us-gaap';
        if (empty($facts['facts']['us-gaap'] ?? null) && ! empty($facts['facts']['ifrs-full'] ?? null)) {
            $taxonomy = 'ifrs-full';
            Log::info('SecApiService: using IFRS taxonomy', [
                'ticker' => $company->ticker,
                'cik' => $company->cik,
            ]);
        }

        $companyFacts = $facts['facts'][$taxonomy] ?? [];

        if (empty($companyFacts)) {
            Log::warning('No financial facts found for company', [
                'ticker' => $company->ticker,
                'cik' => $company->cik,
                'taxonomy' => $taxonomy,
            ]);

            throw new \RuntimeException(
                "No {$taxonomy} financial data found for {$company->ticker}. "
                .'This company may file under a different taxonomy or may not have '
                .'XBRL data available on SEC EDGAR.'
            );
        }

        // Extract metrics using the appropriate taxonomy
        $metrics = $this->extractFinancialMetrics(['facts' => [$taxonomy => $companyFacts]]);

        if ($metrics->isEmpty()) {
            Log::warning('No metrics extracted from SEC facts', [
                'ticker' => $company->ticker,
                'cik' => $company->cik,
                'taxonomy' => $taxonomy,
            ]);

            throw new \RuntimeException(
                "No financial statements were extracted for {$company->ticker}. "
                .'The company may not have 10-K or 10-Q filings in the expected format.'
            );
        }

        // Upsert all extracted metrics
        $imported = collect();
        foreach ($metrics as $metric) {
            // Skip records missing critical fields
            if (empty($metric['fiscal_year']) && empty($metric['reported_date'])) {
                continue;
            }

            $statement = FinancialStatement::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'fiscal_year' => $metric['fiscal_year'],
                    'fiscal_quarter' => $metric['fiscal_quarter'],
                    'filing_type' => $metric['filing_type'],
                ],
                $metric
            );

            $imported->push($statement);
        }

        Log::info('SecApiService: import completed', [
            'ticker' => $company->ticker,
            'cik' => $company->cik,
            'taxonomy' => $taxonomy,
            'records_imported' => $imported->count(),
        ]);

        return $imported;
    }
}
