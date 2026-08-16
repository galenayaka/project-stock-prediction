"""FastAPI microservice for stock price prediction."""

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

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger(__name__)

predictor = StockPredictor()
fetcher = YFinanceFetcher()


@asynccontextmanager
async def lifespan(app: FastAPI):
    logger.info("Starting ML Prediction Service...")
    predictor.load_model()
    yield
    logger.info("Shutting down ML Prediction Service.")


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

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


class TickerRequest(BaseModel):
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
    ticker: str = Field(..., description="Stock symbol.")
    target_period: TargetPeriod = Field(default=TargetPeriod.THREE_MONTHS)


@app.get("/health", response_model=HealthResponse, tags=["System"])
async def health_check() -> HealthResponse:
    return HealthResponse(
        status="ok",
        model_loaded=predictor.is_loaded,
        model_version=predictor.MODEL_VERSION,
    )


@app.post("/api/v1/predict", response_model=PredictionResponse, tags=["Prediction"])
async def predict(request: PredictionRequest) -> PredictionResponse:
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
    try:
        ticker = request.ticker.upper()
        timeframe = request.timeframe
        current_price = request.current_price
        history = request.financial_history

        trading_days = TIME_PERIOD_MAP.get(timeframe, 63)

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

        key_drivers = _compute_key_drivers(enriched, timeframe)

        tech_driver = _compute_technical_alignment(ticker, key_drivers, timeframe)
        tech_alignment_data: dict[str, Any] | None = None
        if tech_driver is not None:
            key_drivers.append(tech_driver)
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

        signal_type, predicted_return, confidence_score, confidence_breakdown = _determine_signal(
            enriched, key_drivers, current_price, trading_days, tech_alignment_data
        )

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
    drivers: list[KeyDriver] = []

    if len(enriched) < 2:
        return drivers

    eps_values = [r.get("eps") for r in enriched if r.get("eps") is not None]
    if len(eps_values) >= 2:
        eps_change = eps_values[-1] - eps_values[0]   # last minus first
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
    if not drivers:
        return None

    positive = sum(1 for d in drivers if d.impact == "positive")
    negative = sum(1 for d in drivers if d.impact == "negative")
    net_score = positive - negative

    if net_score == 0:
        fundamental_direction = "neutral"
    elif net_score > 0:
        fundamental_direction = "bullish"
    else:
        fundamental_direction = "bearish"

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

    tech_score = sum(technical_signals)

    if tech_score >= 1:
        technical_direction = "bullish"
    elif tech_score <= -1:
        technical_direction = "bearish"
    else:
        return None

    detail_parts = []
    if daily_momentum is not None:
        detail_parts.append(f"Daily ({momentum['data_points']}d): {daily_momentum:+.2%}")
    if weekly_momentum is not None:
        detail_parts.append(f"Weekly (4w): {weekly_momentum:+.2%}")
    if green_ratio is not None:
        detail_parts.append(f"Green candles: {green_ratio:.0%}")
    detail = " | ".join(detail_parts)

    if fundamental_direction == "neutral":
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
        direction_label = "Bullish" if technical_direction == "bullish" else "Bearish"
        impact = "positive" if technical_direction == "bullish" else "negative"
        return KeyDriver(
            factor=f"Technical Alignment ({direction_label})",
            impact=impact,
            detail=f"Price momentum confirms fundamental {fundamental_direction} signal. {detail}",
        )
    else:
        direction_label = "Bullish" if technical_direction == "bullish" else "Bearish"
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

    net_score = positive - negative

    base_confidence = 0.40
    driver_bonus = total / 10.0
    raw_confidence = base_confidence + driver_bonus
    confidence = round(min(raw_confidence, 0.95), 4)
    cap_applied = raw_confidence > 0.95

    formula = f"{base_confidence:.0%} base + ({total} drivers × 10%) = {raw_confidence:.0%}"
    if cap_applied:
        formula += " → capped at 95%"

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

    price_returns = [
        r.get("price_return") for r in enriched
        if r.get("price_return") is not None
    ]
    avg_historical_return = (
        sum(price_returns) / len(price_returns) if price_returns else 0.0
    )

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


@app.post("/api/v1/data/stock-info", tags=["Data"])
async def get_stock_info(request: TickerRequest) -> dict[str, Any]:
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


@app.post("/api/v1/train", tags=["Training"])
async def train_model(request: TrainRequest) -> dict[str, Any]:
    try:
        logger.info(
            "Training on %s (period=%s, target_days=%d)...",
            request.ticker,
            request.period,
            request.target_days_ahead,
        )

        df = fetcher.get_training_data(
            request.ticker,
            period=request.period,
            target_days_ahead=request.target_days_ahead,
        )

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


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(
        "main:app",
        host="0.0.0.0",
        port=8001,
        reload=True,
        log_level="info",
    )

