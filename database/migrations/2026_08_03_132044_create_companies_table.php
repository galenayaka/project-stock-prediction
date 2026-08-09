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
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('ticker', 10)->unique();
            $table->string('name');
            $table->string('sector', 100)->nullable();
            $table->string('industry', 100)->nullable();
            $table->unsignedBigInteger('market_cap')->nullable()->comment('Market capitalization in USD');
            $table->text('description')->nullable();
            $table->string('cik', 20)->nullable()->comment('SEC Central Index Key');
            $table->decimal('latest_price', 15, 4)->nullable();
            $table->date('latest_price_date')->nullable();
            $table->timestamps();

            $table->index('ticker');
            $table->index('sector');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
