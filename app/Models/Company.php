<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'ticker',
        'name',
        'sector',
        'industry',
        'market_cap',
        'description',
        'cik',
        'latest_price',
        'latest_price_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'market_cap' => 'integer',
            'latest_price' => 'decimal:4',
            'latest_price_date' => 'date',
        ];
    }

    /**
     * @return HasMany<FinancialStatement>
     */
    public function financialStatements(): HasMany
    {
        return $this->hasMany(FinancialStatement::class);
    }

    /**
     * @return HasMany<Prediction>
     */
    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    /**
     * @return HasMany<UserWatchlist>
     */
    public function watchlists(): HasMany
    {
        return $this->hasMany(UserWatchlist::class);
    }

    /**
     * @return HasMany<DailyPriceHistory>
     */
    public function dailyPriceHistories(): HasMany
    {
        return $this->hasMany(DailyPriceHistory::class);
    }

    /**
     * Get the latest available financial statement (by reported_date).
     */
    public function latestFinancialStatement(): ?FinancialStatement
    {
        /** @var FinancialStatement|null */
        return $this->financialStatements()->latest('reported_date')->first();
    }

    /**
     * Get the latest completed prediction.
     */
    public function latestPrediction(): ?Prediction
    {
        /** @var Prediction|null */
        return $this->predictions()
            ->where('status', 'completed')
            ->latest()
            ->first();
    }

    /**
     * Scope: companies with their latest completed prediction.
     *
     * Uses a subquery to efficiently join the most recent completed
     * prediction for ranking purposes.
     */
    public function scopeWithLatestPrediction(Builder $query): Builder
    {
        return $query->addSelect([
            'latest_prediction_id' => Prediction::select('id')
                ->whereColumn('company_id', 'companies.id')
                ->where('status', 'completed')
                ->latest()
                ->limit(1),
            'latest_signal_type' => Prediction::select('signal_type')
                ->whereColumn('company_id', 'companies.id')
                ->where('status', 'completed')
                ->latest()
                ->limit(1),
            'latest_confidence_score' => Prediction::select('confidence_score')
                ->whereColumn('company_id', 'companies.id')
                ->where('status', 'completed')
                ->latest()
                ->limit(1),
            'latest_predicted_return' => Prediction::select('predicted_return')
                ->whereColumn('company_id', 'companies.id')
                ->where('status', 'completed')
                ->latest()
                ->limit(1),
        ]);
    }

    /**
     * Scope: only companies that have a completed prediction
     * with the given signal type.
     */
    public function scopeWhereLatestSignal(Builder $query, string $signalType): Builder
    {
        return $query->whereHas('predictions', function (Builder $q) use ($signalType): void {
            $q->where('status', 'completed')
                ->where('signal_type', $signalType)
                ->where('confidence_score', '>=', 0.5);
        });
    }
}
