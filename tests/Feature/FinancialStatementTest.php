<?php

use App\Models\Company;
use App\Models\FinancialStatement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->company = Company::factory()->create();

    $this->statement = FinancialStatement::factory()->create([
        'company_id' => $this->company->id,
        'fiscal_year' => 2024,
        'fiscal_quarter' => 1,
        'filing_type' => '10-Q',
        'revenue' => 100_000_000_000,
        'net_income' => 25_000_000_000,
        'eps' => 6.50,
        'pe_ratio' => 28.5,
        'debt_to_equity' => 1.2,
        'roe' => 0.35,
    ]);
});

test('financial statement belongs to company', function (): void {
    expect($this->statement->company->id)->toBe($this->company->id);
});

test('financial statement has proper casts', function (): void {
    expect($this->statement->fiscal_year)->toBeInt();
    expect((float) $this->statement->revenue)->toBeFloat();
    expect((float) $this->statement->eps)->toBeFloat();
    expect($this->statement->reported_date)->toBeInstanceOf(Carbon::class);
});

test('unique constraint prevents duplicate statements', function (): void {
    $this->expectException(QueryException::class);

    FinancialStatement::factory()->create([
        'company_id' => $this->company->id,
        'fiscal_year' => 2024,
        'fiscal_quarter' => 1,
        'filing_type' => '10-Q',
    ]);
});
