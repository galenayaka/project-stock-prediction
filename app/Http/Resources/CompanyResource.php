<?php

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
final class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticker' => $this->ticker,
            'name' => $this->name,
            'sector' => $this->sector,
            'industry' => $this->industry,
            'market_cap' => $this->market_cap,
            'latest_price' => $this->latest_price,
            'latest_price_date' => $this->latest_price_date?->toDateString(),
            'latest_financial_statement' => new FinancialStatementResource($this->whenLoaded('latestFinancialStatement')),
            'latest_prediction' => new PredictionResource($this->whenLoaded('latestPrediction')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
