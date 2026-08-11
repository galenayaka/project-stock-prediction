@echo off
:: ============================================================================
::  StockPrediction — ML Service Launcher
::  Starts the Python prediction API in production mode on port 8001.
::
::  Place this file in your Windows Startup folder or run via Task Scheduler
::  to have the service auto-start on boot.
:: ============================================================================

cd /d "%~dp0ml_service"

echo Starting StockPrediction ML Service...
python run_prod.py

pause
