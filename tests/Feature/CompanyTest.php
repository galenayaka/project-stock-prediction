<?php

use App\Models\Company;
use App\Models\FinancialStatement;
use App\Models\Prediction;

beforeEach(function (): void {
    $this->company = Company::factory()->create([
        'ticker' => 'AAPL',
        'name' => 'Apple Inc.',
        'sector' => 'Technology',
    ]);
});

test('company listing page returns 200', function (): void {
    $response = $this->get(route('companies.index'));

    $response->assertOk();
    $response->assertSee('AAPL');
});

test('company detail page returns 200', function (): void {
    $response = $this->get(route('companies.show', $this->company));

    $response->assertOk();
    $response->assertSee('AAPL');
    $response->assertSee('Apple Inc.');
});

test('company can be created', function (): void {
    $response = $this->post(route('companies.store'), [
        'ticker' => 'MSFT',
        'name' => 'Microsoft Corp.',
        'sector' => 'Technology',
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('companies', [
        'ticker' => 'MSFT',
        'name' => 'Microsoft Corp.',
    ]);
});

test('company has many financial statements', function (): void {
    FinancialStatement::factory()->count(3)->create([
        'company_id' => $this->company->id,
    ]);

    expect($this->company->financialStatements)->toHaveCount(3);
});

test('company has many predictions', function (): void {
    Prediction::factory()->count(2)->create([
        'company_id' => $this->company->id,
    ]);

    expect($this->company->predictions)->toHaveCount(2);
});

test('latest financial statement returns most recent', function (): void {
    $older = FinancialStatement::factory()->create([
        'company_id' => $this->company->id,
        'reported_date' => '2023-01-01',
    ]);

    $newer = FinancialStatement::factory()->create([
        'company_id' => $this->company->id,
        'reported_date' => '2024-01-01',
    ]);

    expect($this->company->latestFinancialStatement()->id)->toBe($newer->id);
});

test('latest prediction returns most recent completed', function (): void {
    Prediction::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'failed',
        'created_at' => now()->subDay(),
    ]);

    $completed = Prediction::factory()->completed()->create([
        'company_id' => $this->company->id,
        'created_at' => now(),
    ]);

    expect($this->company->latestPrediction()->id)->toBe($completed->id);
});
