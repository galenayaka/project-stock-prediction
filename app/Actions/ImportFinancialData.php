<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\FinancialStatement;
use App\Services\SecApiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Action to import financial data from the SEC EDGAR API for a given company.
 */
final class ImportFinancialData
{
    public function __construct(
        private readonly SecApiService $secApi,
    ) {}

    /**
     * Execute the import for a single company.
     *
     * @return Collection<int, FinancialStatement>
     */
    public function handle(Company $company): Collection
    {
        Log::info('Importing financial data from SEC', [
            'company_id' => $company->id,
            'ticker' => $company->ticker,
        ]);

        try {
            $imported = $this->secApi->importForCompany($company);

            Log::info('Financial data import completed', [
                'company_id' => $company->id,
                'ticker' => $company->ticker,
                'records_imported' => $imported->count(),
            ]);

            return $imported;
        } catch (\Throwable $e) {
            Log::error('Financial data import failed', [
                'company_id' => $company->id,
                'ticker' => $company->ticker,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
