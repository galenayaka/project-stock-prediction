<?php

namespace App\Http\Resources;

use App\Models\FinancialStatement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FinancialStatement
 */
final class FinancialStatementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fiscal_year' => $this->fiscal_year,
            'fiscal_quarter' => $this->fiscal_quarter,
            'filing_type' => $this->filing_type,
            'revenue' => $this->revenue,
            'net_income' => $this->net_income,
            'eps' => $this->eps,
            'pe_ratio' => $this->pe_ratio,
            'debt_to_equity' => $this->debt_to_equity,
            'current_ratio' => $this->current_ratio,
            'free_cash_flow' => $this->free_cash_flow,
            'gross_margin' => $this->gross_margin,
            'operating_margin' => $this->operating_margin,
            'roe' => $this->roe,
            'roa' => $this->roa,
            'total_assets' => $this->total_assets,
            'total_liabilities' => $this->total_liabilities,
            'reported_date' => $this->reported_date?->toDateString(),
        ];
    }
}
