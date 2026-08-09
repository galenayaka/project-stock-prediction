<?php

namespace App\Models;

use Database\Factories\PredictionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prediction extends Model
{
    /** @use HasFactory<PredictionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'financial_statement_id',
        'predicted_price',
        'confidence_score',
        'prediction_direction',
        'signal_type',
        'predicted_return',
        'target_period',
        'feature_importance',
        'model_metadata',
        'status',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'predicted_price' => 'decimal:4',
            'confidence_score' => 'decimal:4',
            'predicted_return' => 'decimal:6',
            'feature_importance' => 'array',
            'model_metadata' => 'array',
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
     * @return BelongsTo<FinancialStatement, $this>
     */
    public function financialStatement(): BelongsTo
    {
        return $this->belongsTo(FinancialStatement::class);
    }

    /**
     * Check if this prediction is actionable (has meaningful data).
     */
    public function isActionable(): bool
    {
        return $this->status === 'completed'
            && $this->predicted_price !== null
            && $this->confidence_score !== null
            && $this->confidence_score >= 0.5;
    }

    /**
     * Mark prediction as processing.
     */
    public function markProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    /**
     * Mark prediction as completed with results.
     *
     * @param  array<string, mixed>  $results
     */
    public function markCompleted(array $results): void
    {
        $this->update([
            'status' => 'completed',
            'predicted_price' => $results['predicted_price'] ?? null,
            'confidence_score' => $results['confidence_score'] ?? null,
            'prediction_direction' => $results['prediction_direction'] ?? null,
            'feature_importance' => $results['feature_importance'] ?? null,
            'model_metadata' => $results['model_metadata'] ?? null,
        ]);
    }

    /**
     * Mark prediction as failed.
     */
    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }
}
