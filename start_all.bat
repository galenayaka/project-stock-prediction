@echo off
:: ============================================================================
::  StockPrediction — Start All Services
::  Launches both the ML prediction API and the Laravel development server.
::
::  Double-click this file or run from terminal to start everything at once.
:: ============================================================================

echo ============================================================
echo   StockPrediction — Starting All Services
echo ============================================================
echo.

cd /d "%~dp0"

:: ── Start ML Service ──────────────────────────────────────────
echo [1/2] Starting ML Prediction Service (port 8001)...
start "StockPrediction-ML" cmd /c "cd ml_service && python run_prod.py"

:: Give ML service a moment to start
timeout /t 3 /nobreak >nul

:: ── Start Laravel Dev Server ──────────────────────────────────
echo [2/2] Starting Laravel Development Server (port 8000)...
start "StockPrediction-Laravel" cmd /c "php artisan serve"

echo.
echo ============================================================
echo   All services started!
echo   - ML Service:  http://localhost:8001
echo   - Laravel:     http://localhost:8000
echo ============================================================
echo.
echo Close this window or press any key to exit (services will keep running).
pause >nul
