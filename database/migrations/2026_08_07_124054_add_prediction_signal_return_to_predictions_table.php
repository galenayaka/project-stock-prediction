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
        Schema::table('predictions', function (Blueprint $table): void {
            $table->string('signal_type', 10)->nullable()->after('prediction_direction')
                ->comment('Trading signal: buy, hold, sell');
            $table->decimal('predicted_return', 8, 6)->nullable()->after('signal_type')
                ->comment('Expected return as a decimal (e.g. 0.05 = 5%)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('predictions', function (Blueprint $table): void {
            $table->dropColumn(['signal_type', 'predicted_return']);
        });
    }
};
