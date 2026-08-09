<?php

namespace App\Models;

use Database\Factories\FinancialStatementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialStatement extends Model
{
    /** @use HasFactory<FinancialStatementFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year',
        'fiscal_quarter',
        'filing_type',
        'revenue',
        'net_income',
        'eps',
        'pe_ratio',
        'debt_to_equity',
        'current_ratio',
        'free_cash_flow',
        'gross_margin',
        'operating_margin',
        'roe',
        'roa',
        'total_assets',
        'total_liabilities',
        'shares_outstanding',
        'reported_date',
        'filing_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'fiscal_quarter' => 'integer',
            'revenue' => 'decimal:2',
            'net_income' => 'decimal:2',
            'eps' => 'decimal:4',
            'pe_ratio' => 'decimal:4',
            'debt_to_equity' => 'decimal:4',
            'current_ratio' => 'decimal:4',
            'free_cash_flow' => 'decimal:2',
            'gross_margin' => 'decimal:4',
            'operating_margin' => 'decimal:4',
            'roe' => 'decimal:4',
            'roa' => 'decimal:4',
            'total_assets' => 'decimal:2',
            'total_liabilities' => 'decimal:2',
            'shares_outstanding' => 'integer',
            'reported_date' => 'date',
            'filing_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<Prediction>
     */
    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }
}
