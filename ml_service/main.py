"""
FastAPI Microservice for Stock Market Prediction
================================================

This service provides a REST API that integrates **yfinance** for stock data
retrieval and an **ML ensemble** (XGBoost + RandomForest) for price prediction.

Architecture Overview
---------------------
::

    Laravel (PHP)  ──HTTP──▶  FastAPI (Python)  ──yfinance──▶  Yahoo Finance
         ▲                          │
         │                          ├── models/predictor.py    (ML ensemble)
         │                          ├── services/data_fetcher.py (yfinance wrapper)
         │                          └── schemas/prediction.py   (Pydantic models)
         │
         └── Prediction results returned as JSON

Two Prediction Modes
--------------------
1. **Fundamental mode** — Predict from 11 financial-statement features
   (PE ratio, EPS, ROE, debt-to-equity, etc.). Uses a weighted heuristic
   when the ensemble hasn't been trained on fundamental→price data.
2. **Technical / price-forecast mode** — Train on historical OHLCV prices
   via ``POST /api/v1/train``, then predict future close prices from
   derived technical indicators (moving averages, volatility, returns).

Data Source — yfinance
----------------------
All stock data comes from **yfinance**, a Python library that wraps the
Yahoo Finance API. No API key required. The ``YFinanceFetcher`` class
(in ``services/data_fetcher.py``) normalises responses into structured
dataclasses consumed by the predictor.

Endpoints
---------
+--------------------------------------+-----------------------------------------------+
| Endpoint                             | Purpose                                       |
+======================================+===============================================+
| ``GET  /health``                     | Health check (model status, version)           |
+--------------------------------------+-----------------------------------------------+
| ``POST /api/v1/predict``             | Predict from manually-provided features        |
+--------------------------------------+-----------------------------------------------+
| ``POST /api/v1/predict/from-ticker`` | Fetch features via yfinance, then predict      |
+--------------------------------------+-----------------------------------------------+
| ``POST /api/v1/data/stock-info``     | Get stock metadata (sector, market cap, etc.)  |
+--------------------------------------+-----------------------------------------------+
| ``POST /api/v1/data/historical``     | Get historical OHLCV prices                    |
+--------------------------------------+-----------------------------------------------+
| ``POST /api/v1/data/financial-features`` | Get fundamental features for a ticker      |
+--------------------------------------+-----------------------------------------------+
| ``POST /api/v1/train``               | Train the ensemble on a ticker's price history |
+--------------------------------------+-----------------------------------------------+

Quick Start
-----------
.. code-block:: bash

    cd ml_service
    pip install -r requirements.txt
    python main.py

The server starts on **http://0.0.0.0:8001**.  Open
http://localhost:8001/docs for the interactive Swagger UI.

Example cURL Requests
---------------------

**Health check:**
::

    curl http://localhost:8001/health

**Get Apple stock info:**
::

    curl -X POST http://localhost:8001/api/v1/data/stock-info \
      -H "Content-Type: application/json" \
      -d '{"ticker": "AAPL"}'

**Get historical prices (last 6 months, weekly):**
::

    curl -X POST http://localhost:8001/api/v1/data/historical \
      -H "Content-Type: application/json" \
      -d '{"ticker": "MSFT", "period": "6mo", "interval": "1wk"}'

**Get financial features + predict in one call:**
::

    curl -X POST http://localhost:8001/api/v1/predict/from-ticker \
      -H "Content-Type: application/json" \
      -d '{"ticker": "TSLA", "target_period": "3m"}'

**Train the model on 5 years of AAPL data:**
::

    curl -X POST http://localhost:8001/api/v1/train \
      -H "Content-Type: application/json" \
      -d '{"ticker": "AAPL", "period": "5y", "target_days_ahead": 60}'

**Predict from manual features:**
::

    curl -X POST http://localhost:8001/api/v1/predict \
      -H "Content-Type: application/json" \
      -d '{
        "features": {
          "pe_ratio": 28.5, "debt_to_equity": 1.2, "current_ratio": 1.8,
          "free_cash_flow": 95000000, "gross_margin": 0.44,
          "operating_margin": 0.28, "roe": 0.35, "roa": 0.12,
          "eps": 6.10, "market_cap": 2800000000, "revenue_growth": 0.08,
          "latest_price": 175.0
        },
        "target_period": "3m"
      }'
"""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager
from typing import Any

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field

from models.predictor import StockPredictor
from schemas.prediction import (
    EnhancedPredictionRequest,
    EnhancedPredictionResponse,
    FinancialRecord,
    HealthResponse,
    KeyDriver,
    PredictionRequest,
    PredictionResponse,
    TargetPeriod,
)
from services.data_fetcher import YFinanceFetcher

# ---------------------------------------------------------------------------
# Logging — writes timestamped messages to stdout so you can monitor requests
# ---------------------------------------------------------------------------
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Global singleton instances
#   - predictor : the ML ensemble (XGBoost + RandomForest)
#   - fetcher   : wraps yfinance for all stock data retrieval
# These are created once at import time and reused across every request.
# ---------------------------------------------------------------------------
predictor = StockPredictor()
fetcher = YFinanceFetcher()


# ---------------------------------------------------------------------------
# Lifespan — FastAPI's modern replacement for @app.on_event("startup"/"shutdown")
# ---------------------------------------------------------------------------
@asynccontextmanager
async def lifespan(app: FastAPI):
    """
    Application lifecycle handler.

    On **startup**: initialises the ML predictor (creates XGBoost and
    RandomForest objects, but does NOT train them — training happens
    separately via ``POST /api/v1/train``).

    On **shutdown**: logs a message.  No explicit cleanup is needed
    because model objects are garbage-collected by Python.
    """
    logger.info("Starting ML Prediction Service...")
    predictor.load_model()
    yield
    logger.info("Shutting down ML Prediction Service.")


# ---------------------------------------------------------------------------
# FastAPI Application — metadata appears in /docs and /openapi.json
# ---------------------------------------------------------------------------
app = FastAPI(
    title="Stock Market Prediction Service",
    description=(
        "ML microservice for predicting stock prices from financial features "
        "and yfinance data.  Combine fundamental analysis (PE ratio, EPS, ROE, …) "
        "with technical indicators (moving averages, volatility) via an XGBoost + "
        "RandomForest ensemble."
    ),
    version="1.1.0",
    lifespan=lifespan,
)

# ---------------------------------------------------------------------------
# CORS — allow the Laravel frontend (or any client) to call this service.
# Tighten ``allow_origins`` to your Laravel host in production.
# ---------------------------------------------------------------------------
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


# =============================================================================
# Request Schemas (Pydantic)
# =============================================================================
# These validate incoming JSON automatically.  If a required field is missing
# or has the wrong type, FastAPI returns a 422 error with details.


class TickerRequest(BaseModel):
    """
    Generic request for any yfinance-based endpoint that needs a ticker symbol.

    Attributes:
        ticker:   Stock symbol, e.g. ``"AAPL"``, ``"TSLA"``, ``"MSFT"``.
        period:   Look-back window.  Valid values: ``1d``, ``5d``, ``1mo``,
                  ``3mo``, ``6mo``, ``1y``, ``2y``, ``5y``, ``10y``, ``ytd``,
                  ``max``.
        interval: Candle / bar size.  Valid values: ``1m``, ``5m``, ``15m``,
                  ``30m``, ``1h``, ``1d``, ``1wk``, ``1mo``.
    """

    ticker: str = Field(..., description="Stock symbol, e.g. AAPL, TSLA, MSFT.")
    period: str = Field(
        default="1y",
        description="Look-back period: 1mo, 6mo, 1y, 5y, max, etc.",
    )
    interval: str = Field(
        default="1d",
        description="Candle interval: 1d, 1wk, 1mo.",
    )


class TrainRequest(BaseModel):
    """
    Request to train the ML ensemble on a ticker's historical price data.

    The training pipeline:
    1. Fetch OHLCV data for ``ticker`` via yfinance.
    2. Engineer technical features (moving averages, volatility, returns).
    3. Create a supervised target: closing price ``target_days_ahead`` later.
    4. Split chronologically into train / test sets.
    5. Fit XGBoost and RandomForest regressors.
    6. Return MAE, RMSE, R² on the test set.

    Attributes:
        ticker:            Stock symbol to train on.
        period:            How far back to fetch training data (default ``"5y"``).
        target_days_ahead: Trading days ahead to predict (1–252).
        test_size:         Fraction of data held out for evaluation (0.1–0.4).
    """

    ticker: str = Field(..., description="Stock symbol to train on.")
    period: str = Field(
        default="5y",
        description="How far back to fetch training data.",
    )
    target_days_ahead: int = Field(
        default=60,
        ge=1,
        le=252,
        description="Days ahead to predict (1–252).",
    )
    test_size: float = Field(
        default=0.2,
        ge=0.1,
        le=0.4,
        description="Fraction of data to hold out for testing.",
    )


class PredictFromTickerRequest(BaseModel):
    """
    Convenience request that fetches fundamental features AND runs prediction
    in a single round-trip.

    Attributes:
        ticker:        Stock symbol.
        target_period: Prediction horizon (``1m``, ``3m``, ``6m``, ``1y``).
    """

    ticker: str = Field(..., description="Stock symbol.")
    target_period: TargetPeriod = Field(default=TargetPeriod.THREE_MONTHS)


# =============================================================================
# Routes
# =============================================================================


# ── System ──────────────────────────────────────────────────────────────────


@app.get("/health", response_model=HealthResponse, tags=["System"])
async def health_check() -> HealthResponse:
    """
    Health check — used by Laravel or monitoring tools to verify the service
    is alive and the model is loaded.

    Returns:
        ``HealthResponse`` with ``status``, ``model_loaded``, and ``model_version``.
    """
    return HealthResponse(
        status="ok",
        model_loaded=predictor.is_loaded,
        model_version=predictor.MODEL_VERSION,
    )


# ── Prediction (fundamental mode) ────────────────────────────────────────────


@app.post("/api/v1/predict", response_model=PredictionResponse, tags=["Prediction"])
async def predict(request: PredictionRequest) -> PredictionResponse:
    """
    Generate a stock price prediction **from manually-provided financial features**.

    Use this endpoint when you already have financial-statement data
    (from your own database, another API, or the ``/api/v1/data/financial-features``
    endpoint) and want to run the ML predictor on it.

    The 11 required features are:
        - ``pe_ratio`` — Price-to-earnings ratio
        - ``debt_to_equity`` — Total debt / total equity
        - ``current_ratio`` — Current assets / current liabilities
        - ``free_cash_flow`` — Operating cash flow minus capex
        - ``gross_margin`` — (Revenue - COGS) / Revenue
        - ``operating_margin`` — Operating income / Revenue
        - ``roe`` — Return on equity (net income / equity)
        - ``roa`` — Return on assets
        - ``eps`` — Earnings per share
        - ``market_cap`` — Total market capitalisation
        - ``revenue_growth`` — Year-over-year revenue growth rate

    Also accepts an optional ``latest_price`` key; if provided, the response
    includes a direction signal (bullish / bearish / neutral) relative to
    that price.

    Args:
        request: ``PredictionRequest`` with a ``features`` dict and optional
                 ``target_period``.

    Returns:
        ``PredictionResponse`` with:
        - ``predicted_price`` — forecasted price in USD
        - ``confidence_score`` — 0–1 confidence estimate
        - ``direction`` — ``"bullish"``, ``"bearish"``, or ``"neutral"``
        - ``feature_importance`` — ranked list of which features mattered most
    """
    try:
        features: dict[str, Any] = request.features
        features["target_period"] = request.target_period.value

        result = predictor.predict(features)

        return PredictionResponse(
            predicted_price=result["predicted_price"],
            confidence_score=result["confidence_score"],
            direction=result["direction"],
            feature_importance=result["feature_importance"],
            model=result["model"],
            version=result["version"],
            metadata={
                "target_period": request.target_period.value,
                "features_provided": len(request.features),
            },
        )
    except Exception as e:
        logger.exception("Prediction failed")
        raise HTTPException(status_code=500, detail=f"Prediction error: {str(e)}")


@app.post("/api/v1/predict/from-ticker", tags=["Prediction"])
async def predict_from_ticker(request: PredictFromTickerRequest) -> dict[str, Any]:
    """
    **One-shot convenience endpoint**: fetch fundamental features for a ticker
    via yfinance, then immediately run the ML predictor on those features.

    Internally this does two things:
    1. ``YFinanceFetcher.get_financial_features(ticker)`` — pulls PE ratio,
       EPS, ROE, debt-to-equity, etc. from Yahoo Finance.
    2. ``StockPredictor.predict(features)`` — runs the ensemble / heuristic.

    This saves you from making two separate HTTP calls when you just want a
    quick prediction for a known ticker.

    Args:
        request: ``PredictFromTickerRequest`` with ``ticker`` and optional
                 ``target_period``.

    Returns:
        JSON object containing:
        - ``ticker`` — the symbol that was queried
        - ``current_price`` — latest trading price from yfinance
        - ``predicted_price``, ``confidence_score``, ``direction``,
          ``feature_importance`` — prediction results
        - ``metadata.source`` — always ``"yfinance"``
    """
    try:
        features = fetcher.get_financial_features(request.ticker)
        feature_dict = features.to_dict()

        result = predictor.predict(feature_dict)

        return {
            "ticker": request.ticker.upper(),
            "current_price": features.latest_price,
            **result,
            "metadata": {
                "target_period": request.target_period.value,
                "source": "yfinance",
                "fetched_at": features.fetched_at,
            },
        }
    except Exception as e:
        logger.exception("predict/from-ticker failed for %s", request.ticker)
        raise HTTPException(status_code=500, detail=str(e))


# ── Enhanced Prediction (v2) ───────────────────────────────────────

TIME_PERIOD_MAP: dict[str, int] = {
    "1m": 21,
    "3m": 63,
    "6m": 126,
    "1y": 252,
}


@app.post(
    "/api/v1/predict/enhanced",
    response_model=EnhancedPredictionResponse,
    tags=["Prediction"],
)
async def predict_enhanced(
    request: EnhancedPredictionRequest,
) -> EnhancedPredictionResponse:
    """
    **Enhanced prediction** — accepts a company's full financial-statement
    history from Laravel, enriches it with post-earnings price reactions
    from yfinance, and returns a trading signal with key drivers.

    This is the primary endpoint called by ``StockPredictionService`` in
    Laravel when the user clicks "Run Prediction" on the dashboard.

    Pipeline:
        1. For each historical financial report, fetch the stock price on
           the report date and ``timeframe`` trading days later via yfinance.
        2. Compute the post-earnings price return for each report.
        3. Extract trend signals from the financial ratios (EPS growth,
           margin expansion, ROE trend, etc.).
        4. Combine fundamental analysis with historical price-reaction data
           to produce a signal (buy / hold / sell), expected return %,
           confidence score, and ranked key drivers.

    Args:
        request: ``EnhancedPredictionRequest`` with ticker, timeframe,
                 current_price, and financial_history array.

    Returns:
        ``EnhancedPredictionResponse`` with signal_type, predicted_return,
        confidence_score, key_drivers, current_price, and target_price.
    """
    try:
        ticker = request.ticker.upper()
        timeframe = request.timeframe
        current_price = request.current_price
        history = request.financial_history

        # Determine number of trading days for the timeframe
        trading_days = TIME_PERIOD_MAP.get(timeframe, 63)

        # ── 1. Enrich financial history with yfinance price reactions ──
        enriched: list[dict[str, Any]] = []
        for record in history:
            enriched_record = {
                "fiscal_year": record.fiscal_year,
                "fiscal_quarter": record.fiscal_quarter,
                "filing_type": record.filing_type,
                "revenue": record.revenue,
                "net_income": record.net_income,
                "eps": record.eps,
                "pe_ratio": record.pe_ratio,
                "debt_to_equity": record.debt_to_equity,
                "current_ratio": record.current_ratio,
                "free_cash_flow": record.free_cash_flow,
                "gross_margin": record.gross_margin,
                "operating_margin": record.operating_margin,
                "roe": record.roe,
                "roa": record.roa,
                "reported_date": record.reported_date,
            }

            # If we have a report date, fetch price reaction from yfinance
            if record.reported_date:
                try:
                    price_before, price_after = fetcher.get_price_reaction(
                        ticker,
                        record.reported_date,
                        trading_days,
                    )
                    enriched_record["price_before"] = price_before
                    enriched_record["price_after"] = price_after
                    if price_before and price_before > 0:
                        enriched_record["price_return"] = round(
                            (price_after - price_before) / price_before, 6
                        )
                    else:
                        enriched_record["price_return"] = None
                except Exception:
                    enriched_record["price_before"] = None
                    enriched_record["price_after"] = None
                    enriched_record["price_return"] = None
            else:
                enriched_record["price_before"] = None
                enriched_record["price_after"] = None
                enriched_record["price_return"] = None

            enriched.append(enriched_record)

        # ── 2. Compute fundamental trends ──
        key_drivers = _compute_key_drivers(enriched, timeframe)

        # ── 2b. Compute technical alignment (price momentum) ──
        tech_driver = _compute_technical_alignment(ticker, key_drivers, timeframe)
        tech_alignment_data: dict[str, Any] | None = None
        if tech_driver is not None:
            key_drivers.append(tech_driver)
            # Capture momentum data for the confidence breakdown
            try:
                momentum = fetcher.get_price_momentum(ticker)
                tech_alignment_data = {
                    "driver_added": True,
                    "driver_factor": tech_driver.factor,
                    "driver_impact": tech_driver.impact,
                    "driver_detail": tech_driver.detail,
                    "daily_momentum_pct": momentum.get("daily_momentum_pct"),
                    "weekly_momentum_pct": momentum.get("weekly_momentum_pct"),
                    "green_candle_ratio": momentum.get("green_candle_ratio"),
                    "daily_trend": momentum.get("daily_trend"),
                    "weekly_trend": momentum.get("weekly_trend"),
                    "data_points": momentum.get("data_points"),
                }
            except Exception:
                tech_alignment_data = {
                    "driver_added": True,
                    "driver_factor": tech_driver.factor,
                    "driver_impact": tech_driver.impact,
                    "driver_detail": tech_driver.detail,
                    "error": "Could not fetch full momentum data.",
                }
        else:
            tech_alignment_data = {
                "driver_added": False,
                "reason": "Insufficient data or mixed technical signals — no adjustment applied.",
            }

        # ── 3. Determine signal ──
        signal_type, predicted_return, confidence_score, confidence_breakdown = _determine_signal(
            enriched, key_drivers, current_price, trading_days, tech_alignment_data
        )

        # ── 4. Compute target price ──
        target_price = None
        if current_price and predicted_return is not None:
            target_price = round(current_price * (1.0 + predicted_return), 2)

        return EnhancedPredictionResponse(
            ticker=ticker,
            timeframe=timeframe,
            signal_type=signal_type,
            predicted_return=predicted_return,
            confidence_score=confidence_score,
            confidence_breakdown=confidence_breakdown,
            key_drivers=key_drivers,
            current_price=current_price,
            target_price=target_price,
        )

    except Exception as e:
        logger.exception("Enhanced prediction failed for %s", request.ticker)
        raise HTTPException(status_code=500, detail=str(e))


def _compute_key_drivers(
    enriched: list[dict[str, Any]],
    timeframe: str,
) -> list[KeyDriver]:
    """
    Analyse the enriched financial history to identify the top factors
    driving the prediction signal.

    Checks for:
        - EPS growth / decline trend across reports
        - Margin expansion / contraction
        - ROE / ROA trajectory
        - Debt-to-equity trend
        - Historical price reactions around earnings
    """
    drivers: list[KeyDriver] = []

    if len(enriched) < 2:
        return drivers

    # EPS trend
    eps_values = [r.get("eps") for r in enriched if r.get("eps") is not None]
    if len(eps_values) >= 2:
        eps_change = eps_values[-1] - eps_values[0]
        if eps_change > 0:
            drivers.append(KeyDriver(
                factor="EPS Growth",
                impact="positive",
                detail=f"EPS grew from ${eps_values[0]:.2f} to ${eps_values[-1]:.2f} across {len(eps_values)} reports.",
            ))
        elif eps_change < 0:
            drivers.append(KeyDriver(
                factor="EPS Decline",
                impact="negative",
                detail=f"EPS fell from ${eps_values[0]:.2f} to ${eps_values[-1]:.2f}.",
            ))

    # ROE trend
    roe_values = [r.get("roe") for r in enriched if r.get("roe") is not None]
    if len(roe_values) >= 2:
        roe_trend = roe_values[-1] - roe_values[0]
        if roe_trend > 0.02:
            drivers.append(KeyDriver(
                factor="ROE Improvement",
                impact="positive",
                detail=f"ROE improved by {roe_trend:.1%} over the period.",
            ))
        elif roe_trend < -0.02:
            drivers.append(KeyDriver(
                factor="ROE Deterioration",
                impact="negative",
                detail=f"ROE declined by {abs(roe_trend):.1%}.",
            ))

    # Margin trend (use gross margin)
    margin_values = [r.get("gross_margin") for r in enriched if r.get("gross_margin") is not None]
    if len(margin_values) >= 2:
        margin_trend = margin_values[-1] - margin_values[0]
        if margin_trend > 0.02:
            drivers.append(KeyDriver(
                factor="Margin Expansion",
                impact="positive",
                detail=f"Gross margin expanded by {margin_trend:.1%}.",
            ))
        elif margin_trend < -0.02:
            drivers.append(KeyDriver(
                factor="Margin Contraction",
                impact="negative",
                detail=f"Gross margin contracted by {abs(margin_trend):.1%}.",
            ))

    # Debt-to-equity trend
    dte_values = [r.get("debt_to_equity") for r in enriched if r.get("debt_to_equity") is not None]
    if len(dte_values) >= 2:
        dte_trend = dte_values[-1] - dte_values[0]
        if dte_trend > 0.3:
            drivers.append(KeyDriver(
                factor="Rising Leverage",
                impact="negative",
                detail=f"Debt-to-equity increased by {dte_trend:.2f}.",
            ))
        elif dte_trend < -0.3:
            drivers.append(KeyDriver(
                factor="Deleveraging",
                impact="positive",
                detail=f"Debt-to-equity decreased by {abs(dte_trend):.2f}.",
            ))

    # Historical price reaction
    price_returns = [
        r.get("price_return") for r in enriched
        if r.get("price_return") is not None
    ]
    if price_returns:
        avg_return = sum(price_returns) / len(price_returns)
        if avg_return > 0.03:
            drivers.append(KeyDriver(
                factor="Positive Post-Earnings History",
                impact="positive",
                detail=f"Average post-report return: +{avg_return:.1%} over {len(price_returns)} reports.",
            ))
        elif avg_return < -0.03:
            drivers.append(KeyDriver(
                factor="Negative Post-Earnings History",
                impact="negative",
                detail=f"Average post-report return: {avg_return:.1%}.",
            ))

    return drivers


def _compute_technical_alignment(
    ticker: str,
    drivers: list[KeyDriver],
    timeframe: str,
) -> KeyDriver | None:
    """
    Fetch recent price momentum (daily/weekly OHLCV) via yfinance and
    check whether technical price action aligns with or contradicts the
    fundamental signal direction.

    Logic:
        - Compute preliminary fundamental direction from existing drivers.
        - Fetch 20-day daily momentum + 4-week weekly momentum.
        - If daily AND weekly trends agree with fundamentals → alignment bonus.
        - If both contradict fundamentals → contradiction penalty.
        - Mixed signals → no driver added (neutral).

    Args:
        ticker: Stock symbol.
        drivers: Fundamental key drivers (used to determine direction).
        timeframe: Prediction horizon (1m, 3m, 6m, 1y).

    Returns:
        A KeyDriver if a clear alignment or contradiction is detected,
        otherwise None.
    """
    if not drivers:
        return None

    # Determine fundamental direction from existing drivers
    positive = sum(1 for d in drivers if d.impact == "positive")
    negative = sum(1 for d in drivers if d.impact == "negative")
    net_score = positive - negative

    if net_score == 0:
        fundamental_direction = "neutral"
    elif net_score > 0:
        fundamental_direction = "bullish"
    else:
        fundamental_direction = "bearish"

    # Fetch momentum data
    try:
        momentum = fetcher.get_price_momentum(ticker)
    except Exception:
        logger.warning("Failed to fetch momentum for %s, skipping technical alignment.", ticker)
        return None

    if momentum["data_points"] < 5:
        logger.info("Insufficient price data for %s technical alignment.", ticker)
        return None

    daily_trend = momentum.get("daily_trend")
    weekly_trend = momentum.get("weekly_trend")
    daily_momentum = momentum.get("daily_momentum_pct")
    weekly_momentum = momentum.get("weekly_momentum_pct")
    green_ratio = momentum.get("green_candle_ratio")

    # Build a combined technical direction
    technical_signals = []
    if daily_trend == "bullish":
        technical_signals.append(1)
    elif daily_trend == "bearish":
        technical_signals.append(-1)

    if weekly_trend == "bullish":
        technical_signals.append(1)
    elif weekly_trend == "bearish":
        technical_signals.append(-1)

    if not technical_signals:
        return None

    # Combined technical score (-2 to +2)
    tech_score = sum(technical_signals)

    # Determine technical direction
    if tech_score >= 1:
        technical_direction = "bullish"
    elif tech_score <= -1:
        technical_direction = "bearish"
    else:
        return None  # Mixed signals, no driver

    # ── Build detail string ──
    detail_parts = []
    if daily_momentum is not None:
        detail_parts.append(f"Daily ({momentum['data_points']}d): {daily_momentum:+.2%}")
    if weekly_momentum is not None:
        detail_parts.append(f"Weekly (4w): {weekly_momentum:+.2%}")
    if green_ratio is not None:
        detail_parts.append(f"Green candles: {green_ratio:.0%}")
    detail = " | ".join(detail_parts)

    # ── Alignment check ──
    if fundamental_direction == "neutral":
        # Fundamentals are mixed; technicals provide a directional signal
        if technical_direction == "bullish":
            return KeyDriver(
                factor="Technical Momentum (Bullish)",
                impact="positive",
                detail=f"Price trending up despite mixed fundamentals. {detail}",
            )
        else:
            return KeyDriver(
                factor="Technical Momentum (Bearish)",
                impact="negative",
                detail=f"Price trending down despite mixed fundamentals. {detail}",
            )

    if fundamental_direction == technical_direction:
        # Alignment — bonus confidence
        direction_label = "Bullish" if technical_direction == "bullish" else "Bearish"
        impact = "positive" if technical_direction == "bullish" else "negative"
        return KeyDriver(
            factor=f"Technical Alignment ({direction_label})",
            impact=impact,
            detail=f"Price momentum confirms fundamental {fundamental_direction} signal. {detail}",
        )
    else:
        # Contradiction — penalty
        direction_label = "Bullish" if technical_direction == "bullish" else "Bearish"
        # The impact is opposite of fundamentals — this reduces net_score
        impact = "negative" if fundamental_direction == "bullish" else "positive"
        return KeyDriver(
            factor=f"Technical Contradiction ({direction_label})",
            impact=impact,
            detail=f"Price momentum contradicts fundamental {fundamental_direction} signal! {detail}",
        )


def _determine_signal(
    enriched: list[dict[str, Any]],
    drivers: list[KeyDriver],
    current_price: float | None,
    trading_days: int,
    tech_alignment_data: dict[str, Any] | None = None,
) -> tuple[str, float | None, float, dict[str, Any]]:
    """
    Determine the trading signal (buy/hold/sell), predicted return %,
    confidence score, and a detailed confidence breakdown from the
    enriched data and key drivers.

    Args:
        enriched: List of enriched financial records with price reactions.
        drivers: All key drivers (fundamental + technical alignment).
        current_price: Latest known price.
        trading_days: Trading days for the prediction horizon.
        tech_alignment_data: Optional technical momentum data for the
                             confidence breakdown display.
    """
    positive = sum(1 for d in drivers if d.impact == "positive")
    negative = sum(1 for d in drivers if d.impact == "negative")
    neutral = sum(1 for d in drivers if d.impact == "neutral")
    total = positive + negative

    if total == 0:
        return ("hold", 0.0, 0.3, {
            "base_confidence": 0.40,
            "driver_bonus": 0.0,
            "total_drivers": 0,
            "positive_drivers": 0,
            "negative_drivers": 0,
            "neutral_drivers": neutral,
            "formula": "40% base (no drivers found)",
            "cap_applied": False,
            "technical_alignment": tech_alignment_data,
        })

    # Signal based on driver balance
    net_score = positive - negative

    # Confidence from driver count: base 40% + 10% per driver, capped at 95%
    base_confidence = 0.40
    driver_bonus = total / 10.0
    raw_confidence = base_confidence + driver_bonus
    confidence = round(min(raw_confidence, 0.95), 4)
    cap_applied = raw_confidence > 0.95

    # Build formula explanation
    formula = f"{base_confidence:.0%} base + ({total} drivers × 10%) = {raw_confidence:.0%}"
    if cap_applied:
        formula += f" → capped at 95%"

    confidence_breakdown = {
        "base_confidence": round(base_confidence, 4),
        "driver_bonus": round(driver_bonus, 4),
        "total_drivers": total,
        "positive_drivers": positive,
        "negative_drivers": negative,
        "neutral_drivers": neutral,
        "net_score": net_score,
        "raw_confidence": round(raw_confidence, 4),
        "formula": formula,
        "cap_applied": cap_applied,
        "technical_alignment": tech_alignment_data,
    }

    # Predicted return from historical price reactions
    price_returns = [
        r.get("price_return") for r in enriched
        if r.get("price_return") is not None
    ]
    avg_historical_return = (
        sum(price_returns) / len(price_returns) if price_returns else 0.0
    )

    # Blend fundamentals signal with historical return
    if net_score >= 2:
        predicted_return = round(max(avg_historical_return + 0.02, 0.03), 4)
        signal = "buy"
    elif net_score <= -2:
        predicted_return = round(min(avg_historical_return - 0.02, -0.03), 4)
        signal = "sell"
    else:
        predicted_return = round(avg_historical_return, 4)
        signal = "hold"

    return (signal, predicted_return, confidence, confidence_breakdown)


# ── Data (yfinance) ──────────────────────────────────────────────────────────


@app.post("/api/v1/data/stock-info", tags=["Data"])
async def get_stock_info(request: TickerRequest) -> dict[str, Any]:
    """
    Return structured **stock metadata** for a ticker, fetched from Yahoo Finance.

    This endpoint calls ``yfinance.Ticker(ticker).info`` and extracts the
    most commonly needed fields into a flat JSON response.

    Fields returned:
        - ``ticker`` — normalised uppercase symbol
        - ``company_name`` — long name from Yahoo Finance
        - ``sector`` / ``industry`` — GICS classification
        - ``market_cap`` — total market capitalisation
        - ``current_price`` — latest trading price
        - ``pe_ratio`` — trailing or forward P/E
        - ``eps`` — trailing earnings per share
        - ``beta`` — 5-year monthly beta vs S&P 500
        - ``fifty_two_week_high`` / ``fifty_two_week_low``
        - ``currency`` — typically ``"USD"``
        - ``fetched_at`` — ISO-8601 timestamp of when the data was pulled

    Args:
        request: ``TickerRequest`` with the ticker symbol.

    Returns:
        Dict with the fields listed above.
    """
    try:
        info = fetcher.get_stock_info(request.ticker)
        return {
            "ticker": info.ticker,
            "company_name": info.company_name,
            "sector": info.sector,
            "industry": info.industry,
            "market_cap": info.market_cap,
            "current_price": info.current_price,
            "pe_ratio": info.pe_ratio,
            "eps": info.eps,
            "beta": info.beta,
            "fifty_two_week_high": info.fifty_two_week_high,
            "fifty_two_week_low": info.fifty_two_week_low,
            "currency": info.currency,
            "fetched_at": info.fetched_at,
        }
    except Exception as e:
        logger.exception("stock-info failed for %s", request.ticker)
        raise HTTPException(status_code=500, detail=str(e))


@app.post("/api/v1/data/historical", tags=["Data"])
async def get_historical(request: TickerRequest) -> dict[str, Any]:
    """
    Return **historical OHLCV price data** for a ticker via yfinance.

    This calls ``yfinance.Ticker(ticker).history(period, interval)`` and
    returns the rows as a JSON array.  Each row contains:

    .. code-block:: json

        {
          "date": "2025-01-15T00:00:00-0500",
          "open": 150.0,
          "high": 152.5,
          "low": 149.0,
          "close": 151.2,
          "volume": 55000000
        }

    Args:
        request: ``TickerRequest`` with ``ticker``, ``period``, and ``interval``.

    Returns:
        ``{"ticker": "...", "period": "...", "interval": "...", "count": N, "data": [...]}``
    """
    try:
        data = fetcher.get_historical_prices(
            request.ticker,
            period=request.period,
            interval=request.interval,
        )
        return {
            "ticker": request.ticker.upper(),
            "period": request.period,
            "interval": request.interval,
            "count": len(data),
            "data": data,
        }
    except Exception as e:
        logger.exception("historical failed for %s", request.ticker)
        raise HTTPException(status_code=500, detail=str(e))


@app.post("/api/v1/data/financial-features", tags=["Data"])
async def get_financial_features(request: TickerRequest) -> dict[str, Any]:
    """
    Return the **11 fundamental financial features** that the prediction model
    requires, fetched from Yahoo Finance for the given ticker.

    This is useful when you want to inspect the raw features before running
    a prediction, or store them in a database for later use.

    The returned ``features`` dict contains:
        ``pe_ratio``, ``debt_to_equity``, ``current_ratio``, ``free_cash_flow``,
        ``gross_margin``, ``operating_margin``, ``roe``, ``roa``, ``eps``,
        ``market_cap``, ``revenue_growth``, and ``latest_price``.

    Args:
        request: ``TickerRequest`` with the ticker symbol.

    Returns:
        ``{"ticker": "...", "features": {...}, "latest_price": ..., "fetched_at": "..."}``
    """
    try:
        features = fetcher.get_financial_features(request.ticker)
        return {
            "ticker": features.ticker,
            "features": features.to_dict(),
            "latest_price": features.latest_price,
            "fetched_at": features.fetched_at,
        }
    except Exception as e:
        logger.exception("financial-features failed for %s", request.ticker)
        raise HTTPException(status_code=500, detail=str(e))


# ── Training ─────────────────────────────────────────────────────────────────


@app.post("/api/v1/train", tags=["Training"])
async def train_model(request: TrainRequest) -> dict[str, Any]:
    """
    **Train the ML ensemble** on a ticker's historical price data from yfinance.

    Full training pipeline:
        1. Fetch OHLCV data for the ticker (default: 5 years of daily bars).
        2. Engineer technical features:
           - ``returns_5d`` / ``returns_20d`` — 5- and 20-day price returns
           - ``volatility_20d`` — rolling 20-day standard deviation of returns
           - ``volume_ratio`` — current volume vs 20-day average
           - ``sma_20`` / ``sma_50`` — simple moving averages
           - ``price_to_sma20`` — close price relative to 20-day SMA
        3. Create the supervised target: closing price ``target_days_ahead``
           trading days in the future.
        4. Split chronologically (no shuffle — respects time order).
        5. Scale features with ``StandardScaler``.
        6. Fit XGBoost and RandomForest regressors independently.
        7. Ensemble prediction = average of both models.
        8. Evaluate on the test set: MAE, RMSE, R².

    After training, the model stays in memory and can be used for
    ``predict_price()`` calls.  Note: training replaces any previously
    trained model state.

    Training is ticker-specific.  A model trained on AAPL will not
    generalise well to TSLA — you should train separately per ticker.

    Args:
        request: ``TrainRequest`` with ticker, period, target_days_ahead,
                 and test_size.

    Returns:
        .. code-block:: json

            {
              "ticker": "AAPL",
              "samples": 1200,
              "target_days_ahead": 60,
              "metrics": {"mae": 5.23, "rmse": 7.89, "r2": 0.85},
              "model_version": "1.1.0"
            }

        - ``mae`` — Mean Absolute Error (average dollar error)
        - ``rmse`` — Root Mean Squared Error (penalises large errors)
        - ``r2`` — R² score (1.0 = perfect fit, 0.0 = mean baseline)
    """
    try:
        logger.info(
            "Training on %s (period=%s, target_days=%d)...",
            request.ticker,
            request.period,
            request.target_days_ahead,
        )

        # Step 1: Fetch training data with technical features from yfinance
        df = fetcher.get_training_data(
            request.ticker,
            period=request.period,
            target_days_ahead=request.target_days_ahead,
        )

        # Step 2: Train XGBoost + RandomForest ensemble
        metrics = predictor.train_on_price_history(df, test_size=request.test_size)

        return {
            "ticker": request.ticker.upper(),
            "samples": len(df),
            "target_days_ahead": request.target_days_ahead,
            "metrics": metrics,
            "model_version": predictor.MODEL_VERSION,
        }
    except Exception as e:
        logger.exception("train failed for %s", request.ticker)
        raise HTTPException(status_code=500, detail=str(e))


# =============================================================================
# Entry Point — run with ``python main.py``
# =============================================================================
if __name__ == "__main__":
    import uvicorn

    uvicorn.run(
        "main:app",
        host="0.0.0.0",   # Listen on all network interfaces
        port=8001,         # Port 8001 (separate from Laravel's 8000)
        reload=True,       # Auto-reload on code changes (disable in production)
        log_level="info",
    )

