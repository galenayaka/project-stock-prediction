<?php

namespace App\Models;

use Database\Factories\DailyPriceHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyPriceHistory extends Model
{
    /** @use HasFactory<DailyPriceHistoryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'date',
        'open',
        'high',
        'low',
        'close',
        'volume',
        'price_change_pct',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'open' => 'decimal:4',
            'high' => 'decimal:4',
            'low' => 'decimal:4',
            'close' => 'decimal:4',
            'volume' => 'integer',
            'price_change_pct' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
