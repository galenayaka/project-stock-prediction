"""
Pydantic schemas for the ML prediction service.

These models define the CONTRACT between Laravel (PHP) and FastAPI (Python).
Every request and response is validated against these schemas — if a field
is missing or has the wrong type, FastAPI returns a 422 error automatically.

WHAT IS PYDANTIC?
    Pydantic is a data validation library for Python.  It's like TypeScript
    interfaces but for Python.  When you define a class like:

        class HealthResponse(BaseModel):
            status: str = "ok"
            model_loaded: bool

    Pydantic automatically:
    1. Validates that incoming JSON matches these types
    2. Converts types if possible (e.g., string "123" → int 123)
    3. Generates JSON Schema for the Swagger /docs UI
    4. Returns clear error messages for invalid data

SCHEMA HIERARCHY:
    ┌─────────────────────────────────────────────────────────────┐
    │ PredictionRequest ──→ PredictionResponse                    │
    │   features: dict                                           │
    │   target_period: "1m"|"3m"|"6m"|"1y"                      │
    │                                                             │
    │ EnhancedPredictionRequest ──→ EnhancedPredictionResponse    │
    │   ticker: str                                               │
    │   timeframe: str                                            │
    │   current_price: float                                      │
    │   financial_history: [FinancialRecord, ...]                │
    └─────────────────────────────────────────────────────────────┘
"""

from __future__ import annotations

from enum import Enum
from typing import Any

from pydantic import BaseModel, Field


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

    status: str = "ok"
    model_loaded: bool
    model_version: str


# ── Enhanced prediction schemas (v2) ────────────────────────────


class FinancialRecord(BaseModel):
    """
    A single financial-statement snapshot sent by Laravel for the enhanced
    prediction endpoint.

    This is one row from the `financial_statements` database table.
    Laravel's StockPredictionService builds an array of these (all historical
    filings for a company, oldest first) and sends them to the Python service.

    Each record contains both raw dollar amounts (revenue, net_income) and
    pre-computed ratios (gross_margin, roe). The `reported_date` is critical —
    it lets the Python service look up what the stock price was on that date
    and how it moved afterward (post-earnings drift).
    """

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
    """
    Request payload for the enhanced prediction endpoint.

    Laravel sends the full financial-statement history plus the company's
    current price.  The Python service enriches this with yfinance price
    reactions around each report date.
    """

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
    """
    Response payload for the enhanced prediction endpoint — what the
    dashboard displays after clicking "Run Prediction."

    FIELD-BY-FIELD EXPLANATION:
    ┌─────────────────────┬──────────────────────────────────────────┐
    │ ticker              │ The stock symbol (e.g., "AAPL")          │
    │ timeframe           │ Prediction horizon ("1m","3m","6m","1y") │
    │ signal_type         │ "buy", "hold", or "sell"                 │
    │ predicted_return    │ Expected % return (e.g., 0.052 = +5.2%)  │
    │ confidence_score    │ 0.0–1.0 (e.g., 0.75 = 75% confident)    │
    │ confidence_breakdown│ How the score was calculated (formula,    │
    │                     │ driver counts, technical alignment)       │
    │ key_drivers         │ Ranked list of what drove the signal     │
    │                     │ (EPS Growth, ROE Improvement, etc.)      │
    │ current_price       │ Latest known price from the database     │
    │ target_price        │ Projected price at end of timeframe      │
    └─────────────────────┴──────────────────────────────────────────┘
    """

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
