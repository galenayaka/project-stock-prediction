<?php

use App\Models\Company;
use App\Models\FinancialStatement;
use App\Models\Prediction;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->statement = FinancialStatement::factory()->create(['company_id' => $this->company->id]);
});

test('prediction belongs to company', function (): void {
    $prediction = Prediction::factory()->create([
        'company_id' => $this->company->id,
        'financial_statement_id' => $this->statement->id,
    ]);

    expect($prediction->company->id)->toBe($this->company->id);
});

test('prediction belongs to financial statement', function (): void {
    $prediction = Prediction::factory()->create([
        'company_id' => $this->company->id,
        'financial_statement_id' => $this->statement->id,
    ]);

    expect($prediction->financialStatement->id)->toBe($this->statement->id);
});

test('isActionable returns true for completed high-confidence predictions', function (): void {
    $prediction = Prediction::factory()->completed()->create([
        'company_id' => $this->company->id,
        'predicted_price' => 150.00,
        'confidence_score' => 0.85,
    ]);

    expect($prediction->isActionable())->toBeTrue();
});

test('isActionable returns false for low-confidence predictions', function (): void {
    $prediction = Prediction::factory()->completed()->create([
        'company_id' => $this->company->id,
        'predicted_price' => 150.00,
        'confidence_score' => 0.35,
    ]);

    expect($prediction->isActionable())->toBeFalse();
});

test('isActionable returns false for non-completed predictions', function (): void {
    $prediction = Prediction::factory()->pending()->create([
        'company_id' => $this->company->id,
        'predicted_price' => null,
        'confidence_score' => null,
    ]);

    expect($prediction->isActionable())->toBeFalse();
});

test('markProcessing updates status', function (): void {
    $prediction = Prediction::factory()->pending()->create([
        'company_id' => $this->company->id,
    ]);

    $prediction->markProcessing();

    expect($prediction->fresh()->status)->toBe('processing');
});

test('markCompleted populates prediction results', function (): void {
    $prediction = Prediction::factory()->pending()->create([
        'company_id' => $this->company->id,
    ]);

    $prediction->markCompleted([
        'predicted_price' => 175.50,
        'confidence_score' => 0.78,
        'prediction_direction' => 'bullish',
        'feature_importance' => ['pe_ratio' => 0.3, 'roe' => 0.2],
        'model_metadata' => ['model' => 'xgboost', 'version' => '1.0'],
    ]);

    $fresh = $prediction->fresh();

    expect($fresh->status)->toBe('completed');
    expect((float) $fresh->predicted_price)->toBe(175.50);
    expect((float) $fresh->confidence_score)->toBe(0.78);
    expect($fresh->prediction_direction)->toBe('bullish');
    expect($fresh->feature_importance)->toBeArray();
});

test('markFailed records error', function (): void {
    $prediction = Prediction::factory()->pending()->create([
        'company_id' => $this->company->id,
    ]);

    $prediction->markFailed('ML service unreachable');

    $fresh = $prediction->fresh();

    expect($fresh->status)->toBe('failed');
    expect($fresh->error_message)->toBe('ML service unreachable');
});
