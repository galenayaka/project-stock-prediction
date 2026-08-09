"""
Yahoo Finance data fetcher using the yfinance library.

Provides:
- Historical OHLCV price data for any ticker
- Financial statement metrics (income, balance sheet, cash flow)
- Derived financial ratios needed by the prediction model
- Stock metadata (sector, market cap, etc.)
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from typing import Any

import pandas as pd
import yfinance as yf

logger = logging.getLogger(__name__)


@dataclass
class StockInfo:
    """Normalized container for stock metadata."""
    ticker: str
    company_name: str
    sector: str | None
    industry: str | None
    market_cap: float | None
    current_price: float | None
    pe_ratio: float | None
    eps: float | None
    beta: float | None
    fifty_two_week_high: float | None
    fifty_two_week_low: float | None
    currency: str = "USD"
    fetched_at: str = field(default_factory=lambda: datetime.utcnow().isoformat())


@dataclass
class FinancialFeatures:
    """
    Feature vector that maps directly to the StockPredictor's
    REQUIRED_FEATURES: pe_ratio, debt_to_equity, current_ratio,
    free_cash_flow, gross_margin, operating_margin, roe, roa,
    eps, market_cap, revenue_growth.
    """
    ticker: str
    pe_ratio: float
    debt_to_equity: float
    current_ratio: float
    free_cash_flow: float
    gross_margin: float
    operating_margin: float
    roe: float
    roa: float
    eps: float
    market_cap: float
    revenue_growth: float
    latest_price: float | None = None
    fetched_at: str = field(default_factory=lambda: datetime.utcnow().isoformat())

    def to_dict(self) -> dict[str, float]:
        """Convert to the feature dict expected by StockPredictor.predict()."""
        return {
            "pe_ratio": self.pe_ratio,
            "debt_to_equity": self.debt_to_equity,
            "current_ratio": self.current_ratio,
            "free_cash_flow": self.free_cash_flow,
            "gross_margin": self.gross_margin,
            "operating_margin": self.operating_margin,
            "roe": self.roe,
            "roa": self.roa,
            "eps": self.eps,
            "market_cap": self.market_cap,
            "revenue_growth": self.revenue_growth,
            "latest_price": self.latest_price,
        }


class YFinanceFetcher:
    """
    Fetches stock data from Yahoo Finance using yfinance.

    Usage:
        fetcher = YFinanceFetcher()
        info = fetcher.get_stock_info("AAPL")
        features = fetcher.get_financial_features("AAPL")
        history = fetcher.get_historical_prices("AAPL", period="1y")
    """

    def __init__(self) -> None:
        pass

    # ── Stock metadata ──────────────────────────────────────────

    def get_stock_info(self, ticker: str) -> StockInfo:
        """Return structured stock metadata for a ticker."""
        t = yf.Ticker(ticker)
        info = t.info or {}

        def _safe_float(key: str) -> float | None:
            val = info.get(key)
            if val is None:
                return None
            try:
                return float(val)
            except (ValueError, TypeError):
                return None

        return StockInfo(
            ticker=ticker.upper(),
            company_name=info.get("longName") or info.get("shortName", ticker),
            sector=info.get("sector"),
            industry=info.get("industry"),
            market_cap=_safe_float("marketCap"),
            current_price=_safe_float("currentPrice")
            or _safe_float("regularMarketPrice")
            or _safe_float("previousClose"),
            pe_ratio=_safe_float("trailingPE") or _safe_float("forwardPE"),
            eps=_safe_float("trailingEps"),
            beta=_safe_float("beta"),
            fifty_two_week_high=_safe_float("fiftyTwoWeekHigh"),
            fifty_two_week_low=_safe_float("fiftyTwoWeekLow"),
            currency=info.get("currency", "USD"),
        )

    # ── Historical prices ───────────────────────────────────────

    def get_historical_prices(
        self,
        ticker: str,
        period: str = "1y",
        interval: str = "1d",
    ) -> list[dict[str, Any]]:
        """
        Fetch historical OHLCV data.

        Args:
            ticker: Stock symbol (e.g. 'AAPL').
            period: One of 1d, 5d, 1mo, 3mo, 6mo, 1y, 2y, 5y, 10y, ytd, max.
            interval: One of 1m, 2m, 5m, 15m, 30m, 60m, 90m, 1h, 1d, 5d, 1wk, 1mo, 3mo.

        Returns:
            List of OHLCV candles serializable as JSON.
        """
        t = yf.Ticker(ticker)
        df: pd.DataFrame = t.history(period=period, interval=interval)

        if df.empty:
            logger.warning("No historical data returned for %s (period=%s)", ticker, period)
            return []

        df = df.reset_index()
        # yfinance returns a Date column (TZ-aware); convert to string
        if "Date" in df.columns:
            df["Date"] = df["Date"].dt.strftime("%Y-%m-%dT%H:%M:%S%z")

        # Rename columns to lowercase snake_case
        df.columns = [c.lower().replace(" ", "_") for c in df.columns]

        return df.where(pd.notna(df), None).to_dict(orient="records")

    # ── Financial features for prediction ───────────────────────

    def get_financial_features(self, ticker: str) -> FinancialFeatures:
        """
        Extract the 11 financial features required by StockPredictor
        from Yahoo Finance fundamentals.

        Uses the latest annual (and quarterly where needed) statements.
        """
        t = yf.Ticker(ticker)
        info = t.info or {}

        def _safe(key: str) -> float:
            val = info.get(key)
            if val is None:
                return 0.0
            try:
                return float(val)
            except (ValueError, TypeError):
                return 0.0

        # ── Balance-sheet derived ratios ──
        total_debt = _safe("totalDebt")
        total_equity = _safe("totalStockholderEquity") or _safe("shareholdersEquity") or 1.0
        debt_to_equity = round(total_debt / total_equity, 6) if total_equity else 0.0

        total_current_assets = _safe("totalCurrentAssets")
        total_current_liabilities = _safe("totalCurrentLiabilities") or 1.0
        current_ratio = round(total_current_assets / total_current_liabilities, 4) if total_current_liabilities else 0.0

        # ── Cash flow ──
        free_cash_flow = _safe("freeCashflow")

        # ── Profitability ──
        gross_margin = _safe("grossMargins")  # already a ratio
        operating_margin = _safe("operatingMargins")
        roe = _safe("returnOnEquity")         # already a ratio; can be > 1
        roa = _safe("returnOnAssets")

        # ── Per-share / valuation ──
        pe_ratio = _safe("trailingPE") or _safe("forwardPE")
        eps = _safe("trailingEps")
        market_cap = _safe("marketCap")

        # ── Growth ──
        revenue_growth = _safe("revenueGrowth")  # YoY %

        # ── Latest price ──
        latest_price = _safe("currentPrice") or _safe("regularMarketPrice") or _safe("previousClose")

        return FinancialFeatures(
            ticker=ticker.upper(),
            pe_ratio=pe_ratio,
            debt_to_equity=debt_to_equity,
            current_ratio=current_ratio,
            free_cash_flow=free_cash_flow,
            gross_margin=gross_margin,
            operating_margin=operating_margin,
            roe=roe,
            roa=roa,
            eps=eps,
            market_cap=market_cap,
            revenue_growth=revenue_growth,
            latest_price=latest_price,
        )

    # ── Training data ───────────────────────────────────────────

    def get_training_data(
        self,
        ticker: str,
        period: str = "5y",
        target_days_ahead: int = 60,
    ) -> pd.DataFrame:
        """
        Build a training DataFrame with historical prices as the target.

        For each row, computes the future price `target_days_ahead` trading
        days later as the label.  This can be used to train a supervised
        regression model.

        Args:
            ticker: Stock symbol.
            period: Look-back window for historical data (e.g. '5y').
            target_days_ahead: How many trading days ahead to predict.

        Returns:
            DataFrame with columns: date, open, high, low, close, volume,
            target_close, plus derived technical features.
        """
        t = yf.Ticker(ticker)
        df: pd.DataFrame = t.history(period=period)

        if df.empty:
            raise ValueError(f"No historical data for ticker '{ticker}' (period={period})")

        # Target: close price shifted backward by target_days_ahead
        df["target_close"] = df["Close"].shift(-target_days_ahead)

        # Simple technical features
        df["returns_5d"] = df["Close"].pct_change(5)
        df["returns_20d"] = df["Close"].pct_change(20)
        df["volatility_20d"] = df["Close"].pct_change().rolling(20).std()
        df["volume_ratio"] = df["Volume"] / df["Volume"].rolling(20).mean()
        df["sma_20"] = df["Close"].rolling(20).mean()
        df["sma_50"] = df["Close"].rolling(50).mean()
        df["price_to_sma20"] = df["Close"] / df["sma_20"]

        # Drop rows with NaN (from rolling windows and shifted target)
        df = df.dropna()

        return df

    # ── Price reaction around earnings ─────────────────────────……─

    def get_price_reaction(
        self,
        ticker: str,
        report_date_str: str,
        trading_days: int = 63,
    ) -> tuple[float | None, float | None]:
        """
        Fetch the stock price on a report date and ``trading_days`` later.

        Used by the enhanced prediction endpoint to include post-earnings
        price reactions as a feature.

        Args:
            ticker:           Stock symbol.
            report_date_str:  ISO-8601 date string of the financial report.
            trading_days:     Number of trading days to look ahead.

        Returns:
            (price_before, price_after) — each is a float or None if
            data is unavailable.
        """
        try:
            report_date = datetime.fromisoformat(report_date_str.replace("Z", "+00:00"))
        except (ValueError, TypeError):
            logger.warning("Invalid report date '%s' for %s", report_date_str, ticker)
            return (None, None)

        # Fetch from 30 days before report to look_ahead days after
        start_date = report_date - timedelta(days=30)
        end_date = report_date + timedelta(days=trading_days + 30)

        t = yf.Ticker(ticker)
        df: pd.DataFrame = t.history(start=start_date, end=end_date)

        if df.empty:
            return (None, None)

        # Find the closest trading day on or before the report date
        report_ts = pd.Timestamp(report_date)
        before = df[df.index <= report_ts]
        if before.empty:
            return (None, None)

        price_before = float(before.iloc[-1]["Close"])

        # Find the price trading_days trading days after the report
        # Take the row at the index position of the report date + trading_days
        report_idx = before.index[-1]
        try:
            report_pos = df.index.get_loc(report_idx)
            after_pos = min(report_pos + trading_days, len(df) - 1)
            price_after = float(df.iloc[after_pos]["Close"])
        except (KeyError, IndexError):
            return (price_before, None)

        return (price_before, price_after)

    # ── Price Momentum (Technical Analysis) ─────────────────────

    def get_price_momentum(
        self,
        ticker: str,
        daily_lookback: int = 20,
        weekly_lookback: int = 4,
    ) -> dict[str, Any]:
        """
        Fetch recent OHLCV data and compute price momentum metrics
        for technical alignment scoring.

        Computes:
            - daily_momentum_pct: net close change over last N trading days
            - weekly_momentum_pct: net close change over last N weeks
            - green_candle_ratio: fraction of days where Close > Open
            - daily_trend: 'bullish' if daily_momentum > 2%, 'bearish' if < -2%
            - weekly_trend: 'bullish' if weekly_momentum > 3%, 'bearish' if < -3%
            - avg_daily_change_pct: mean day-over-day close % change

        Args:
            ticker: Stock symbol.
            daily_lookback: Number of trading days for daily momentum.
            weekly_lookback: Number of weeks for weekly momentum.

        Returns:
            Dict with momentum metrics.  Values are None when data is
            unavailable.
        """
        try:
            # Fetch enough data for both daily and weekly windows
            total_days = max(daily_lookback, weekly_lookback * 5) + 10
            t = yf.Ticker(ticker)
            df: pd.DataFrame = t.history(period=f"{total_days}d")

            if df.empty or len(df) < 5:
                logger.warning("Insufficient price data for %s momentum", ticker)
                return self._empty_momentum()

            closes = df["Close"].values
            opens = df["Open"].values

            # ── Daily momentum ──
            daily_idx = min(daily_lookback, len(closes) - 1)
            if daily_idx > 0 and closes[-1] > 0 and closes[-daily_idx] > 0:
                daily_momentum_pct = round(
                    (closes[-1] - closes[-daily_idx]) / closes[-daily_idx], 6
                )
            else:
                daily_momentum_pct = None

            # ── Weekly momentum (approximate: every 5 trading days) ──
            weekly_idx = min(weekly_lookback * 5, len(closes) - 1)
            if weekly_idx > 0 and closes[-1] > 0 and closes[-weekly_idx] > 0:
                weekly_momentum_pct = round(
                    (closes[-1] - closes[-weekly_idx]) / closes[-weekly_idx], 6
                )
            else:
                weekly_momentum_pct = None

            # ── Green candle ratio ──
            green_candles = sum(
                1 for o, c in zip(opens[-daily_lookback:], closes[-daily_lookback:])
                if c > o
            )
            candle_count = min(daily_lookback, len(closes))
            green_candle_ratio = round(green_candles / candle_count, 4) if candle_count > 0 else None

            # ── Average daily change ──
            if len(closes) >= 2:
                daily_changes = [
                    (closes[i] - closes[i - 1]) / closes[i - 1]
                    for i in range(max(1, len(closes) - daily_lookback), len(closes))
                    if closes[i - 1] > 0
                ]
                avg_daily_change_pct = (
                    round(sum(daily_changes) / len(daily_changes), 6)
                    if daily_changes else None
                )
            else:
                avg_daily_change_pct = None

            # ── Trend determination ──
            daily_trend = None
            if daily_momentum_pct is not None:
                if daily_momentum_pct > 0.02:
                    daily_trend = "bullish"
                elif daily_momentum_pct < -0.02:
                    daily_trend = "bearish"
                else:
                    daily_trend = "neutral"

            weekly_trend = None
            if weekly_momentum_pct is not None:
                if weekly_momentum_pct > 0.03:
                    weekly_trend = "bullish"
                elif weekly_momentum_pct < -0.03:
                    weekly_trend = "bearish"
                else:
                    weekly_trend = "neutral"

            return {
                "daily_momentum_pct": daily_momentum_pct,
                "weekly_momentum_pct": weekly_momentum_pct,
                "green_candle_ratio": green_candle_ratio,
                "daily_trend": daily_trend,
                "weekly_trend": weekly_trend,
                "avg_daily_change_pct": avg_daily_change_pct,
                "data_points": len(closes),
            }

        except Exception:
            logger.exception("Failed to compute momentum for %s", ticker)
            return self._empty_momentum()

    @staticmethod
    def _empty_momentum() -> dict[str, Any]:
        return {
            "daily_momentum_pct": None,
            "weekly_momentum_pct": None,
            "green_candle_ratio": None,
            "daily_trend": None,
            "weekly_trend": None,
            "avg_daily_change_pct": None,
            "data_points": 0,
        }

    # ── Batch / multi-ticker ────────────────────────────────────

    def get_multiple_infos(self, tickers: list[str]) -> dict[str, StockInfo]:
        """Fetch StockInfo for multiple tickers in one call."""
        results: dict[str, StockInfo] = {}
        for tkr in tickers:
            try:
                results[tkr.upper()] = self.get_stock_info(tkr)
            except Exception as exc:
                logger.warning("Failed to fetch info for %s: %s", tkr, exc)
        return results

    def get_multiple_features(self, tickers: list[str]) -> dict[str, FinancialFeatures]:
        """Fetch FinancialFeatures for multiple tickers."""
        results: dict[str, FinancialFeatures] = {}
        for tkr in tickers:
            try:
                results[tkr.upper()] = self.get_financial_features(tkr)
            except Exception as exc:
                logger.warning("Failed to fetch features for %s: %s", tkr, exc)
        return results
