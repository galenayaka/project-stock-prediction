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
        Schema::create('daily_price_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('open', 15, 4)->nullable();
            $table->decimal('high', 15, 4)->nullable();
            $table->decimal('low', 15, 4)->nullable();
            $table->decimal('close', 15, 4)->nullable();
            $table->bigInteger('volume')->nullable();
            $table->decimal('price_change_pct', 10, 6)->nullable()
                ->comment('((close - open) / open) * 100');
            $table->timestamps();

            $table->unique(['company_id', 'date']);
            $table->index(['company_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_price_histories');
    }
};
