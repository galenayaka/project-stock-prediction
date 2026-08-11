"""
Production launcher for the ML Prediction Service.

Usage:
    python run_prod.py

Differs from ``main.py``:
    - No hot-reload (``reload=False``)
    - Single worker (increase ``workers`` if you have multiple CPU cores)
    - Designed to be run as a Windows Service or via Task Scheduler
"""

import uvicorn

if __name__ == "__main__":
    uvicorn.run(
        "main:app",
        host="0.0.0.0",
        port=8001,
        reload=False,
        workers=1,
        log_level="info",
    )
