<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\SecApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to fetch financial data from SEC EDGAR for a given company.
 * Rate-limited to comply with SEC EDGAR's 10 requests/second limit.
 */
final class FetchFinancialData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 2;

    /**
     * Delete the job if its model no longer exists.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly Company $company,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SecApiService $secApi): void
    {
        Log::info('FetchFinancialData job started', [
            'company_id' => $this->company->id,
            'ticker' => $this->company->ticker,
        ]);

        try {
            $imported = $secApi->importForCompany($this->company);

            Log::info('FetchFinancialData job completed', [
                'company_id' => $this->company->id,
                'ticker' => $this->company->ticker,
                'records' => $imported->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('FetchFinancialData job failed', [
                'company_id' => $this->company->id,
                'ticker' => $this->company->ticker,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get the tags for the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['fetch-financial-data', "company:{$this->company->id}"];
    }
}
