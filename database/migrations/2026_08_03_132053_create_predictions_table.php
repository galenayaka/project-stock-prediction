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
        Schema::create('predictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_statement_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('predicted_price', 15, 4)->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable()->comment('0.00 to 1.00');
            $table->enum('prediction_direction', ['bullish', 'bearish', 'neutral'])->nullable();
            $table->enum('target_period', ['1m', '3m', '6m', '1y'])->default('3m');
            $table->json('feature_importance')->nullable();
            $table->json('model_metadata')->nullable()->comment('Model version, hyperparams, etc.');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['company_id', 'target_period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
