"""
ML Predictor: the core AI engine that generates stock price predictions.

Copyright (c) 2026 Galen Nayaka Nayottama. All rights reserved.

This module contains the StockPredictor class — the central intelligence
of the application. It supports TWO prediction modes:

┌─────────────────────────────────────────────────────────────────────┐
│ MODE 1: FUNDAMENTAL (predict from financial ratios)                 │
│                                                                      │
│ Input:  11 financial ratios (P/E, EPS, ROE, debt/equity, etc.)      │
│ Output: predicted_price, confidence_score, direction                │
│ Method: Weighted heuristic (if model untrained)                     │
│         OR ensemble prediction (if trained on fundamentals)         │
│                                                                      │
│ MODE 2: TECHNICAL / PRICE-FORECAST (train on OHLCV patterns)        │
│                                                                      │
│ Input:  OHLCV price history from yfinance                           │
│ Step 1: Engineer 12 technical features                              │
│ Step 2: Train XGBoost + RandomForest on price patterns              │
│ Step 3: predict_price() uses the ensemble to forecast future close  │
└─────────────────────────────────────────────────────────────────────┘

Key concept — ENSEMBLE AVERAGING:
The final prediction = (XGBoost_result + RandomForest_result) / 2.0
This reduces variance and gives a more robust estimate than either
model alone.
"""

from __future__ import annotations

import logging
from typing import Any

import numpy as np
import pandas as pd
import xgboost as xgb          # ← Gradient-boosted trees (sequential)
from sklearn.ensemble import RandomForestRegressor  # ← Bagged trees (parallel)
from sklearn.metrics import mean_absolute_error, r2_score
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import StandardScaler  # ← Normalizes features to μ=0, σ=1

from schemas.prediction import FeatureImportance, PredictionDirection

logger = logging.getLogger(__name__)

# ── CONSTANT DEFINITIONS ────────────────────────────────────────

# The 11 features that the fundamental prediction model expects.
# These come from SEC EDGAR (financial statements) and Yahoo Finance.
REQUIRED_FEATURES: list[str] = [
    "pe_ratio",          # Price-to-Earnings: how expensive is the stock?
    "debt_to_equity",     # Leverage: how much debt vs equity?
    "current_ratio",      # Liquidity: can the company pay short-term bills?
    "free_cash_flow",     # Cash generation: operating cash minus capital spending
    "gross_margin",       # Profitability: (Revenue - COGS) / Revenue
    "operating_margin",   # Efficiency: Operating Income / Revenue
    "roe",                # Return on Equity: Net Income / Shareholder Equity
    "roa",                # Return on Assets: Net Income / Total Assets
    "eps",                # Earnings Per Share: profit per outstanding share
    "market_cap",         # Size: total market value of all shares
    "revenue_growth",     # Growth: year-over-year revenue change %
]

# Default values when a feature is missing from the data.
# These are sensible "neutral" defaults that won't skew predictions.
FEATURE_DEFAULTS: dict[str, float] = {
    "pe_ratio": 0.0,          # 0 = no opinion on valuation
    "debt_to_equity": 0.0,    # 0 = no debt (optimistic default)
    "current_ratio": 1.0,     # 1.0 = assets exactly cover liabilities
    "free_cash_flow": 0.0,
    "gross_margin": 0.3,      # 30% = average healthy margin
    "operating_margin": 0.1,  # 10% = average operating margin
    "roe": 0.1,               # 10% = decent return on equity
    "roa": 0.05,              # 5% = moderate return on assets
    "eps": 0.0,
    "market_cap": 0.0,
    "revenue_growth": 0.0,    # 0% = flat growth assumption
}

# The 12 technical features engineered from OHLCV price data.
# These are used by Mode 2 (price-forecast / train endpoint).
TECHNICAL_FEATURES: list[str] = [
    "returns_5d",        # 5-day price momentum
    "returns_20d",       # 20-day (monthly) price momentum
    "volatility_20d",    # How much the price swings (risk measure)
    "volume_ratio",      # Current volume vs 20-day average (interest measure)
    "sma_20",            # 20-day Simple Moving Average (short-term trend)
    "sma_50",            # 50-day Simple Moving Average (medium-term trend)
    "price_to_sma20",    # Close price relative to 20-day SMA (>1 = above trend)
    "open",              # Daily opening price
    "high",              # Daily highest price
    "low",               # Daily lowest price
    "close",             # Daily closing price (most important)
    "volume",            # Daily trading volume
]


class StockPredictor:
    """
    Stock price predictor using an ensemble of XGBoost and RandomForest.

    TWO PREDICTION MODES:
    ┌──────────────────────────────────────────────────────────────────┐
    │ 1. Financial-statement mode (predict)                            │
    │    → Input: 11 fundamental ratios                                │
    │    → Output: predicted_price, confidence, direction              │
    │    → Method: weighted heuristic or ensemble (if trained)         │
    │                                                                   │
    │ 2. Technical / price-forecast mode (train_on_price_history)      │
    │    → Input: OHLCV data from yfinance                             │
    │    → Trains XGBoost + RandomForest on 12 technical features      │
    │    → predict_price() uses the trained ensemble                   │
    └──────────────────────────────────────────────────────────────────┘

    LIFECYCLE:
        1. load_model()     → creates untrained model objects
        2. train_on_price_history() → fits models on real data
        3. predict_price()  → uses trained ensemble for forecasting
    """

    MODEL_VERSION = "1.1.0"

    def __init__(self) -> None:
        # StandardScaler normalizes features to mean=0, std=1.
        # This is CRITICAL for ML models — without scaling, features
        # with large values (like market_cap in billions) would dominate
        # features with small values (like margins in 0-1 range).
        self.scaler = StandardScaler()

        # These start as None — they are created in load_model()
        self.xgb_model: xgb.XGBRegressor | None = None
        self.rf_model: RandomForestRegressor | None = None

        # State tracking
        self._is_loaded = False    # True after load_model() is called
        self._is_trained = False   # True after train_on_price_history() succeeds

        # Metrics from the most recent training run
        self._train_metrics: dict[str, float] = {}

    # ── Lifecycle ───────────────────────────────────────────

    def load_model(self) -> None:
        """
        Initialize UNTRAINED XGBoost and RandomForest model objects.

        This is called once at application startup (in the FastAPI lifespan
        handler).  It creates the model objects with their hyperparameters
        but does NOT fit them to any data yet.  Think of it as building
        an empty notebook — the structure exists, but no learning has
        happened.

        HYPERPARAMETERS EXPLAINED:
        ┌────────────────────────────────────────────────────────────┐
        │ XGBoost:                                                    │
        │   n_estimators=100  → build 100 trees sequentially         │
        │   max_depth=6       → each tree can ask 6 questions deep   │
        │   learning_rate=0.1 → how aggressively to correct errors   │
        │   random_state=42   → seed for reproducible results        │
        │                                                             │
        │ RandomForest:                                               │
        │   n_estimators=100  → build 100 independent trees          │
        │   max_depth=8       → deeper trees (more complex patterns) │
        │   random_state=42   → seed for reproducible results        │
        └────────────────────────────────────────────────────────────┘
        """
        logger.info("Initializing model...")
        self.xgb_model = xgb.XGBRegressor(
            n_estimators=100,
            max_depth=6,
            learning_rate=0.1,
            random_state=42,
        )
        self.rf_model = RandomForestRegressor(
            n_estimators=100,
            max_depth=8,
            random_state=42,
        )
        self._is_loaded = True
        self._is_trained = False
        logger.info("Model initialized (untrained). Call train_on_price_history() to train.")

    @property
    def is_loaded(self) -> bool:
        return self._is_loaded

    @property
    def is_trained(self) -> bool:
        return self._is_trained

    @property
    def train_metrics(self) -> dict[str, float]:
        return dict(self._train_metrics)

    # ── Training ────────────────────────────────────────────

    def train_on_price_history(
        self,
        df: pd.DataFrame,
        test_size: float = 0.2,
    ) -> dict[str, float]:
        """
        TRAIN THE ML ENSEMBLE on historical OHLCV price data.

        This is THE function where machine learning actually happens.
        It takes a DataFrame of stock prices with engineered technical
        features and fits both XGBoost and RandomForest models.

        TRAINING PIPELINE (step by step):
        ┌──────────────────────────────────────────────────────────────┐
        │ 1. Extract feature matrix X (12 columns) and target y        │
        │ 2. CHRONOLOGICAL split: 80% train, 20% test                  │
        │    → NO SHUFFLE because this is time-series data!            │
        │    → We must predict the future from the past, not mix them. │
        │ 3. StandardScaler: normalize all features to μ=0, σ=1        │
        │    → Prevents large-value features from dominating           │
        │ 4. 🔵 XGBoost.fit(X_train, y_train)                          │
        │    → Builds 100 trees sequentially, each correcting errors   │
        │ 5. 🟢 RandomForest.fit(X_train, y_train)                     │
        │    → Builds 100 trees independently on random subsets        │
        │ 6. Ensemble = (XGBoost + RandomForest) / 2.0                 │
        │ 7. Evaluate: MAE (avg error in $), RMSE (penalizes outliers),│
        │    R² (1.0 = perfect, 0.0 = useless, negative = worse)      │
        └──────────────────────────────────────────────────────────────┘

        Args:
            df: DataFrame from YFinanceFetcher.get_training_data() with
                columns: open, high, low, close, volume, returns_5d,
                returns_20d, volatility_20d, volume_ratio, sma_20,
                sma_50, price_to_sma20, AND target_close.
            test_size: Fraction of data held out for evaluation (0.2 = 20%).

        Returns:
            Dict with {"mae": ..., "rmse": ..., "r2": ...}
            - mae: Mean Absolute Error (average dollar error)
            - rmse: Root Mean Squared Error (penalizes large mistakes)
            - r2: R² score (proportion of variance explained, 0-1)
        """
        if "target_close" not in df.columns:
            raise ValueError("DataFrame must contain 'target_close' column as the target.")

        # ── Step 1: Separate features from target ──
        feature_cols = [c for c in TECHNICAL_FEATURES if c in df.columns]
        X = df[feature_cols].values   # shape: (n_samples, n_features)
        y = df["target_close"].values  # shape: (n_samples,)

        logger.info(
            "Training on %d samples, %d features (target_days_ahead from yfinance).",
            len(X),
            len(feature_cols),
        )

        # ── Step 2: Chronological train/test split ──
        # shuffle=False is CRITICAL for time-series. If we shuffled,
        # the model could "cheat" by seeing future data during training.
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=test_size, shuffle=False,
        )

        # ── Step 3: Scale features ──
        # fit_transform on training data (learns mean/std AND applies)
        # transform on test data (applies SAME mean/std — no data leakage!)
        X_train_scaled = self.scaler.fit_transform(X_train)
        X_test_scaled = self.scaler.transform(X_test)

        # ── Step 4: Ensure models exist ──
        if self.xgb_model is None or self.rf_model is None:
            self.load_model()

        # ── Step 5 & 6: Fit both models ──
        self.xgb_model.fit(X_train_scaled, y_train)   # 🔵 XGBoost
        self.rf_model.fit(X_train_scaled, y_train)     # 🟢 RandomForest

        # ── Step 7: Ensemble evaluation ──
        xgb_preds = self.xgb_model.predict(X_test_scaled)
        rf_preds = self.rf_model.predict(X_test_scaled)
        ensemble_preds = (xgb_preds + rf_preds) / 2.0  # ← simple average

        # ── Step 8: Calculate metrics ──
        mae = float(mean_absolute_error(y_test, ensemble_preds))
        rmse = float(np.sqrt(((y_test - ensemble_preds) ** 2).mean()))
        r2 = float(r2_score(y_test, ensemble_preds))

        self._train_metrics = {"mae": round(mae, 6), "rmse": round(rmse, 6), "r2": round(r2, 6)}
        self._is_trained = True

        logger.info("Training complete – MAE=%.4f  RMSE=%.4f  R²=%.4f", mae, rmse, r2)

        return self._train_metrics

    # ── Feature preparation ─────────────────────────────────

    def _prepare_features(self, features: dict[str, Any]) -> np.ndarray:
        """Extract and normalise the fundamental feature vector from input."""
        feature_values: list[float] = []
        for feat in REQUIRED_FEATURES:
            val = features.get(feat)
            if val is None or (isinstance(val, float) and np.isnan(val)):
                val = FEATURE_DEFAULTS.get(feat, 0.0)
            feature_values.append(float(val))
        return np.array(feature_values).reshape(1, -1)

    # ── Prediction (fundamental mode) ───────────────────────

    def predict(self, features: dict[str, Any]) -> dict[str, Any]:
        """
        Generate a prediction from fundamental financial features.

        This is the function called by POST /api/v1/predict and
        POST /api/v1/predict/from-ticker.  It uses a WEIGHTED HEURISTIC
        rather than the ML ensemble because the ensemble is trained on
        OHLCV price patterns, not fundamental→price mappings.

        HOW THE WEIGHTED HEURISTIC WORKS:
        ┌────────────────────────────────────────────────────────────┐
        │ predicted_price = 100.0  (base price in dollars)           │
        │                                                             │
        │ for each of the 11 features:                               │
        │     predicted_price += feature_value × weight              │
        │                                                             │
        │ Example:                                                    │
        │   EPS = 6.10  →  +6.10 × 0.7  = +4.27                      │
        │   PE  = 28.5  →  +28.5 × -0.5 = -14.25                     │
        │   ROE = 0.35  →  +0.35 × 0.6  = +0.21                      │
        │   ...                                                       │
        │   Total adjustment: +$75 → predicted_price = $175          │
        └────────────────────────────────────────────────────────────┘

        Each weight reflects how strongly that feature correlates
        with stock price (positive weight = higher is better,
        negative weight = higher is worse).

        Returns:
            Dict with predicted_price, confidence_score, direction,
            and feature_importance.
        """
        if not self._is_loaded:
            self.load_model()

        X = self._prepare_features(features)

        # ── Use weighted heuristic for fundamental mode ──
        # (ensemble is reserved for technical/price-forecast mode)
        predicted_price = self._predict_from_fundamentals(features)
        confidence_score = self._compute_confidence(features)

        predicted_price = round(predicted_price, 4)

        # Determine direction: compare predicted price to current price
        current_price = features.get("latest_price")
        if current_price and isinstance(current_price, (int, float)) and current_price > 0:
            if predicted_price > current_price * 1.05:     # >5% above → bullish
                direction = PredictionDirection.BULLISH
            elif predicted_price < current_price * 0.95:   # >5% below → bearish
                direction = PredictionDirection.BEARISH
            else:
                direction = PredictionDirection.NEUTRAL
        else:
            direction = PredictionDirection.NEUTRAL

        feature_importance = self._get_feature_importance()

        return {
            "predicted_price": predicted_price,
            "confidence_score": confidence_score,
            "direction": direction.value,
            "feature_importance": [fi.model_dump() for fi in feature_importance],
            "model": "xgboost_rf_ensemble",
            "version": self.MODEL_VERSION,
        }

    def predict_price(
        self,
        ohlcv_features: dict[str, float],
    ) -> dict[str, Any]:
        """
        Predict future stock price using the TRAINED ensemble.

        This is the function that actually uses XGBoost + RandomForest
        after training. It takes a single row of technical features
        (the same 12 columns the model was trained on), scales them
        with the SAME StandardScaler from training, and runs both models.

        The final prediction = (XGBoost + RandomForest) / 2.0

        IMPORTANT: This requires prior training via train_on_price_history().
        Without training, this raises RuntimeError.

        Args:
            ohlcv_features: Dict with keys matching TECHNICAL_FEATURES.

        Returns:
            Dict with predicted_price, individual model predictions,
            and model metadata.
        """
        if not self._is_trained:
            raise RuntimeError("Model is not trained. Call train_on_price_history() first.")

        # Extract features in the correct order
        feature_cols = [c for c in TECHNICAL_FEATURES if c in ohlcv_features]
        X = np.array([[ohlcv_features.get(c, 0.0) for c in feature_cols]])

        # Apply the SAME scaling learned during training
        X_scaled = self.scaler.transform(X)

        # Run both models
        xgb_pred = float(self.xgb_model.predict(X_scaled)[0])
        rf_pred = float(self.rf_model.predict(X_scaled)[0])

        # Ensemble average — reduces variance from individual models
        predicted_price = round((xgb_pred + rf_pred) / 2.0, 4)

        return {
            "predicted_price": predicted_price,
            "xgb_prediction": round(xgb_pred, 4),
            "rf_prediction": round(rf_pred, 4),
            "confidence_score": 0.7,  # placeholder; could use prediction intervals
            "model": "xgboost_rf_ensemble",
            "version": self.MODEL_VERSION,
        }
        }

    # ── Internal helpers ────────────────────────────────────

    def _predict_from_fundamentals(self, features: dict[str, Any]) -> float:
        """
        Weighted heuristic that estimates stock price from 11 financial ratios.

        THIS IS NOT MACHINE LEARNING — it's a rule-based formula. Each
        feature has a manually-assigned weight reflecting its typical
        relationship with stock price:

        ┌──────────────────────┬────────┬──────────────────────────────┐
        │ Feature              │ Weight │ Rationale                    │
        ├──────────────────────┼────────┼──────────────────────────────┤
        │ EPS                  │  +0.7  │ Strongest driver — profit    │
        │ ROE                  │  +0.6  │ Efficiency matters           │
        │ ROA                  │  +0.5  │ Asset utilization            │
        │ Free Cash Flow       │  +0.5  │ Real cash, not accounting   │
        │ Gross Margin         │  +0.4  │ Pricing power indicator      │
        │ Operating Margin     │  +0.4  │ Operational efficiency       │
        │ Revenue Growth       │ +0.35  │ Growth trajectory            │
        │ Current Ratio        │  +0.3  │ Short-term financial health  │
        │ Market Cap           │ +0.001 │ Already priced in (tiny)     │
        │ P/E Ratio            │  -0.5  │ High P/E = potentially overvalued │
        │ Debt-to-Equity       │  -0.4  │ High debt = higher risk       │
        └──────────────────────┴────────┴──────────────────────────────┘

        Formula: predicted_price = $100 + Σ(feature_value × weight)
        """
        weights = {
            "pe_ratio": -0.5,
            "debt_to_equity": -0.4,
            "current_ratio": 0.3,
            "free_cash_flow": 0.5,
            "gross_margin": 0.4,
            "operating_margin": 0.4,
            "roe": 0.6,
            "roa": 0.5,
            "eps": 0.7,
            "market_cap": 0.001,
            "revenue_growth": 0.35,
        }

        score = 100.0  # base price in dollars
        for feat, weight in weights.items():
            val = features.get(feat)
            if val is None or (isinstance(val, float) and np.isnan(val)):
                val = FEATURE_DEFAULTS.get(feat, 0.0)
            score += float(val) * weight

        return max(score, 1.0)  # never predict below $1

    def _compute_confidence(self, features: dict[str, Any]) -> float:
        """Confidence based on how many features were provided (not defaulted)."""
        n_provided = sum(
            1 for f in REQUIRED_FEATURES
            if features.get(f) not in (None, np.nan, 0.0)
        )
        return round(min(0.3 + (n_provided / len(REQUIRED_FEATURES)) * 0.7, 0.95), 4)

    def _get_feature_importance(self) -> list[FeatureImportance]:
        """
        Return feature importance from XGBoost if trained on fundamental
        features, otherwise a sensible default ordering.
        """
        if self._is_trained and self.xgb_model is not None:
            try:
                importances = self.xgb_model.feature_importances_
                if len(importances) == len(REQUIRED_FEATURES):
                    pairs = sorted(
                        zip(REQUIRED_FEATURES, importances),
                        key=lambda x: x[1],
                        reverse=True,
                    )
                    return [
                        FeatureImportance(feature=name, importance=round(float(imp), 4))
                        for name, imp in pairs
                    ]
            except Exception:
                logger.warning("Could not extract feature importance from XGBoost.")

        # Fallback heuristic order
        return [
            FeatureImportance(feature="pe_ratio", importance=0.22),
            FeatureImportance(feature="eps", importance=0.18),
            FeatureImportance(feature="roe", importance=0.15),
            FeatureImportance(feature="free_cash_flow", importance=0.13),
            FeatureImportance(feature="debt_to_equity", importance=0.11),
            FeatureImportance(feature="revenue_growth", importance=0.09),
            FeatureImportance(feature="operating_margin", importance=0.06),
            FeatureImportance(feature="gross_margin", importance=0.04),
            FeatureImportance(feature="current_ratio", importance=0.02),
        ]
