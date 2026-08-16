from __future__ import annotations

from enum import Enum
from typing import Any

from pydantic import BaseModel, ConfigDict, Field


class TargetPeriod(str, Enum):
    ONE_MONTH = "1m"
    THREE_MONTHS = "3m"
    SIX_MONTHS = "6m"
    ONE_YEAR = "1y"


class PredictionDirection(str, Enum):
    BULLISH = "bullish"
    BEARISH = "bearish"
    NEUTRAL = "neutral"


class PredictionRequest(BaseModel):
    """Request payload for a prediction."""

    features: dict[str, float | str | None] = Field(
        ...,
        description="Dictionary of feature name to value. "
        "Must include: pe_ratio, debt_to_equity, current_ratio, "
        "free_cash_flow, gross_margin, operating_margin, roe, roa, eps.",
    )
    target_period: TargetPeriod = Field(
        default=TargetPeriod.THREE_MONTHS,
        description="Prediction target period.",
    )


class FeatureImportance(BaseModel):
    """Individual feature importance entry."""

    feature: str
    importance: float


class PredictionResponse(BaseModel):
    """Response payload from a prediction."""

    predicted_price: float | None = Field(
        default=None, description="Predicted stock price in USD."
    )
    confidence_score: float | None = Field(
        default=None, ge=0.0, le=1.0, description="Model confidence score (0-1)."
    )
    direction: PredictionDirection | None = Field(
        default=None, description="Predicted direction (bullish/bearish/neutral)."
    )
    feature_importance: list[FeatureImportance] | None = Field(
        default=None, description="Ordered list of feature importance scores."
    )
    model: str = Field(default="xgboost", description="Model name used for prediction.")
    version: str = Field(default="1.0.0", description="Model version.")
    metadata: dict[str, Any] = Field(
        default_factory=dict, description="Additional model metadata."
    )


class HealthResponse(BaseModel):
    """Health check response."""

    model_config = ConfigDict(protected_namespaces=())

    status: str = "ok"
    model_loaded: bool
    model_version: str


class FinancialRecord(BaseModel):
    """A single financial-statement snapshot from the Laravel database."""

    fiscal_year: int = Field(..., description="Fiscal year of the report.")
    fiscal_quarter: int = Field(..., description="Fiscal quarter (1-4).")
    filing_type: str = Field(..., description="Filing type, e.g. '10-K' or '10-Q'.")
    revenue: float | None = Field(default=None, description="Total revenue.")
    net_income: float | None = Field(default=None, description="Net income / loss.")
    eps: float | None = Field(default=None, description="Earnings per share.")
    pe_ratio: float | None = Field(default=None, description="Price-to-earnings ratio.")
    debt_to_equity: float | None = Field(default=None, description="Debt-to-equity ratio.")
    current_ratio: float | None = Field(default=None, description="Current ratio.")
    free_cash_flow: float | None = Field(default=None, description="Free cash flow.")
    gross_margin: float | None = Field(default=None, description="Gross margin ratio.")
    operating_margin: float | None = Field(default=None, description="Operating margin ratio.")
    roe: float | None = Field(default=None, description="Return on equity.")
    roa: float | None = Field(default=None, description="Return on assets.")
    reported_date: str | None = Field(
        default=None, description="ISO-8601 date the report was filed."
    )


class EnhancedPredictionRequest(BaseModel):
    ticker: str = Field(..., description="Stock symbol, e.g. AAPL.")
    timeframe: str = Field(
        default="3m",
        description="Prediction horizon: '1m', '3m', '6m', '1y'.",
    )
    current_price: float | None = Field(
        default=None, description="Latest known price."
    )
    financial_history: list[FinancialRecord] = Field(
        default_factory=list,
        description="Ordered list of historical financial statements (oldest first).",
    )


class KeyDriver(BaseModel):
    """A single factor that influenced the prediction."""

    factor: str = Field(..., description="Factor name, e.g. 'EPS Growth'.")
    impact: str = Field(..., description="'positive' | 'negative' | 'neutral'.")
    detail: str = Field(default="", description="Human-readable explanation.")


class EnhancedPredictionResponse(BaseModel):
    ticker: str
    timeframe: str
    signal_type: str = Field(..., description="'buy' | 'hold' | 'sell'.")
    predicted_return: float | None = Field(
        default=None, description="Expected return % over the timeframe."
    )
    confidence_score: float = Field(
        default=0.0, ge=0.0, le=1.0, description="Model confidence (0-1)."
    )
    confidence_breakdown: dict[str, Any] = Field(
        default_factory=dict,
        description=(
            "Detailed confidence breakdown showing base confidence, "
            "driver bonus, driver counts, and the formula used."
        ),
    )
    key_drivers: list[KeyDriver] = Field(
        default_factory=list, description="Factors driving the signal."
    )
    current_price: float | None = None
    target_price: float | None = Field(
        default=None, description="Predicted price at end of timeframe."
    )
    model: str = "xgboost_rf_ensemble"
    version: str = "1.1.0"
