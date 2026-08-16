<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\PredictionController;
use App\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    // Companies
    Route::apiResource('companies', CompanyController::class)
        ->only(['index', 'show', 'store'])
        ->names([
            'index' => 'companies.index',
            'show' => 'companies.show',
            'store' => 'companies.store',
        ]);

    // Predictions (nested under companies)
    Route::prefix('companies/{company}')->name('companies.')->group(function (): void {
        Route::get('predictions', [PredictionController::class, 'index'])->name('predictions.index');
        Route::post('predictions', [PredictionController::class, 'store'])->name('predictions.store');
        Route::post('import', [CompanyController::class, 'apiImportFinancials'])->name('import');
    });

    Route::get('predictions/{prediction}', [PredictionController::class, 'show'])->name('predictions.show');

    // Watchlist (authenticated)
    Route::middleware('auth:sanctum')->prefix('watchlist')->name('watchlist.')->group(function (): void {
        Route::get('/', [WatchlistController::class, 'index'])->name('index');
        Route::post('/', [WatchlistController::class, 'store'])->name('store');
        Route::delete('/{watchlist}', [WatchlistController::class, 'destroy'])->name('destroy');
    });
});
