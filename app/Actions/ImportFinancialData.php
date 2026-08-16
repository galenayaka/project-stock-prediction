<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\FinancialStatement;
use App\Services\SecApiService;
use Illuminate\Support\Collection;

/**
 * Action to import financial data from the SEC EDGAR API for a given company.
 */
final class ImportFinancialData
{
    public function __construct(
        private readonly SecApiService $secApi,
    ) {}

    /**
     * @return Collection<int, FinancialStatement>
     */
    public function handle(Company $company): Collection
    {
        return $this->secApi->importForCompany($company);
    }
}
