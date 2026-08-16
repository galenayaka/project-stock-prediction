<?php

use App\Http\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('companies.index'));

Route::prefix('companies')->name('companies.')->controller(CompanyController::class)->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::get('/rankings', 'rankings')->name('rankings');
    Route::get('/{company}', 'show')->name('show');
    Route::post('/', 'store')->name('store');
    Route::post('/{company}/import', 'importFinancials')->name('import');
    Route::post('/{company}/predict', 'triggerPrediction')->name('predict');
});
