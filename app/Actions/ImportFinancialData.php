<?php

namespace App\Actions;

use App\Models\Company;
use App\Services\SecApiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class ImportFinancialData
{
    public function __construct(
        private readonly SecApiService $secApi,
    ) {}

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
