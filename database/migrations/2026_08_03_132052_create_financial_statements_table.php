<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('financial_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->year('fiscal_year');
            $table->unsignedTinyInteger('fiscal_quarter')->comment('1-4 for quarterly, 0 for annual');
            $table->enum('filing_type', ['10-K', '10-Q']);
            $table->decimal('revenue', 20, 2)->nullable()->comment('Total revenue in USD');
            $table->decimal('net_income', 20, 2)->nullable();
            $table->decimal('eps', 12, 4)->nullable()->comment('Earnings per share');
            $table->decimal('pe_ratio', 12, 4)->nullable();
            $table->decimal('debt_to_equity', 12, 4)->nullable();
            $table->decimal('current_ratio', 12, 4)->nullable();
            $table->decimal('free_cash_flow', 20, 2)->nullable();
            $table->decimal('gross_margin', 8, 4)->nullable()->comment('As decimal, e.g. 0.45 = 45%');
            $table->decimal('operating_margin', 8, 4)->nullable();
            $table->decimal('roe', 8, 4)->nullable()->comment('Return on Equity');
            $table->decimal('roa', 8, 4)->nullable()->comment('Return on Assets');
            $table->decimal('total_assets', 20, 2)->nullable();
            $table->decimal('total_liabilities', 20, 2)->nullable();
            $table->unsignedBigInteger('shares_outstanding')->nullable();
            $table->date('reported_date');
            $table->date('filing_date')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year', 'fiscal_quarter', 'filing_type'], 'uq_financial_stmt');
            $table->index('reported_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_statements');
    }
};
